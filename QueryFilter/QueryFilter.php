<?php declare(strict_types = 1);

namespace Artprima\QueryFilterBundle\QueryFilter;

use Artprima\QueryFilterBundle\Exception\InvalidArgumentException;
use Artprima\QueryFilterBundle\QueryFilter\Config\ConfigInterface;
use Artprima\QueryFilterBundle\Response\ResponseInterface;

/**
 * Class QueryFilter
 *
 * @author Denis Voytyuk <ask@artprima.cz>
 */
class QueryFilter
{
    /**
     * @var string
     */
    private $responseClassName;

    /**
     * QueryFilter constructor.
     * @param string $responseClassName
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     */
    public function __construct(string $responseClassName)
    {
        $refClass = new \ReflectionClass($responseClassName);
        if (!$refClass->implementsInterface(ResponseInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Response class "%s" must implement "%s"',
                $responseClassName,
                ResponseInterface::class
            ));
        }

        $constructor = $refClass->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                'Response class "%s" must have a constructor without required parameters',
                $responseClassName
            ));
        }

        $this->responseClassName = $responseClassName;
    }

    /**
     * @return int current page number
     */
    private function getCurrentPage(ConfigInterface $config): int
    {
        $curPage = $config->getRequest()->getPageNum();

        if ($curPage < 1) {
            $curPage = 1;
        }

        return $curPage;
    }

    /**
     * @param ConfigInterface $config
     * @return array
     */
    private function getSortData(ConfigInterface $config): array
    {
        $sort = [
            'field' => $config->getRequest()->getSortBy(),
            'type' => $config->getRequest()->getSortDir(),
        ];
        $sortData = $config->getSortColsDefault();
        if (isset($sort['field'], $sort['type'])) {
            if (in_array($sort['type'], array('asc', 'desc'), true) && in_array($sort['field'], $config->getSortCols(), true)) {
                $sortData = array($sort['field'] => $sort['type']);
            }
        }

        return $sortData;
    }

    private function getSimpleSearchBy(array $allowedCols, ?array $search): array
    {
        $searchBy = [];

        if ($search === null) {
            return $searchBy;
        }

        foreach ($search as $key => $val) {
            if (in_array($key, $allowedCols, true) && $val !== null) {
                $searchBy[$key] = array(
                    'type' => 'like',
                    'val' => $val,
                );
                if (strpos($key, 'GroupConcat') !== false) {
                    $searchBy[$key]['having'] = true;
                }
            }
        }

        return $searchBy;
    }

    private function getFullSearchBy(array $allowedCols, ?array $search): array
    {
        $searchBy = [];

        if ($search === null) {
            return $searchBy;
        }

        foreach ($search as $data) {
            if (!empty($data) && is_array($data) && isset($data['field']) && in_array($data['field'], $allowedCols, true)) {
                $field = $data['field'];
                unset($data['field']);
                $searchBy[$field] = $data;
                if (strpos($field, 'GroupConcat') !== false) {
                    $searchBy[$field]['having'] = true;
                }
            }
        }

        return $searchBy;
    }

    private function replaceSearchByAliases(array &$searchBy, array $aliases)
    {
        foreach ($aliases as $alias => $value) {
            if (empty($searchBy[$alias])) {
                continue;
            }
            if (!empty($value['data'])) {
                $searchBy[$value['name']] = $value['data'];
                $searchBy[$value['name']]['val'] = $searchBy[$alias]['val'];
            } else {
                $searchBy[$value['name']] = $searchBy[$alias];
            }
            unset($searchBy[$alias]);
        }
    }

    private function replaceSpacesWithPercents(array &$searchBy)
    {
        // Replace spaces by %
        foreach ($searchBy as &$item) {
            if (is_array($item) && isset($item['type'], $item['val']) && ($item['type'] === 'like') && preg_match('/[\s\.,]+/', $item['val'])) {
                $words = preg_split('/[\s\.,]+/', $item['val']);
                $item['val'] = $words ? implode('%', $words) : $item['val'];
            }
        }
    }

    /**
     * Get searchby data prepared for query builder
     *
     * If simple, $search must be set to:
     * <code>
     *     $this->searchData = array(
     *         'column_name1' => 'search_value1',
     *         'column_name2' => 'search_value2',
     *     );
     * </code>
     * All comparisons will be treated as "like" here.
     *
     * If not simple, $search must be set to:
     * <code>
     *     $this->searchData = array(
     *         array('field' => 'column_name1', 'type' => 'like', 'val' => 'search_value1'),
     *         array('field' => 'column_name2', 'type' => 'eq', 'val' => 'search_value2'),
     *     );
     * </code>
     *
     * For both cases GroupConcat columns the result will receive extra $searchBy["column_name1"]["having"] = true
     *
     * @param ConfigInterface $config
     * @return array
     */
    private function getSearchBy(ConfigInterface $config): array
    {
        // Get basic search by
        $searchBy = $config->getRequest()->isSimple()
            ? $this->getSimpleSearchBy($config->getSearchAllowedCols(), $config->getRequest()->getQuery())
            : $this->getFullSearchBy($config->getSearchAllowedCols(), $config->getRequest()->getQuery());

        // Set search aliases to more complicated expressions
        $this->replaceSearchByAliases($searchBy, $config->getSearchByAliases());

        // Set search extra filters (can be used to display entries for one particular entity,
        // or to add some extra conditions/filterings)
        $searchBy = array_merge($searchBy, $config->getSearchByExtra());

        // Replace spaces with %
        $this->replaceSpacesWithPercents($searchBy);

        return $searchBy;
    }

    private function getQueryFilterArgs(ConfigInterface $config): QueryFilterArgs
    {
        $searchBy = $this->getSearchBy($config);
        $currentPage = $this->getCurrentPage($config);
        $sortData = $this->getSortData($config);

        $limit = $config->getRequest()->getLimit();
        $allowedLimits = $config->getAllowedLimits();
        if ($limit === -1 || (!empty($allowedLimits) && !in_array($limit, $config->getAllowedLimits(), true))) {
            $limit = $config->getDefaultLimit();
        }

        $args = (new QueryFilterArgs())
            ->setSearchBy($searchBy)
            ->setSortBy($sortData)
            ->setLimit($limit)
            ->setOffset(($currentPage - 1) * $limit);

        return $args;
    }

    private function getFilterData(ConfigInterface $config, QueryFilterArgs $args): QueryResult
    {
        // Query database to obtain corresponding entities
        $repositoryCallback = $config->getRepositoryCallback();

        // $repositoryCallback can be an array, but since PHP 7.0 it's possible to use it as a function directly
        // i.e. without using call_user_func[_array]().
        // For the reference: https://trowski.com/2015/06/20/php-callable-paradox/
        if (!\is_callable($repositoryCallback)) {
            throw new InvalidArgumentException('Repository callback is not callable');
        }

        $filterData = $repositoryCallback($args);

        return $filterData;
    }

    /**
     * Gets filtered data
     *
     * @param ConfigInterface $config
     * @return ResponseInterface
     */
    public function getData(ConfigInterface $config): ResponseInterface
    {
        $args = $this->getQueryFilterArgs($config);

        $startTime = microtime(true);
        $filterData = $this->getFilterData($config, $args);
        $duration = microtime(true) - $startTime;

        /** @var ResponseInterface $response */
        $response = new $this->responseClassName;
        $response->setData($filterData->getResult());
        $response->addMeta('total_records', $filterData->getTotalRows());
        $response->addMeta('metrics', array(
            'query_and_transformation' => $duration,
        ));

        return $response;
    }
}
