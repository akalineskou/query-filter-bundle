<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ArtprimaQueryFilterBundleTest extends WebTestCase
{
    public function testSearchProductsActionSuccess()
    {
        $client = static::createClient();
        self::assertTrue(true); // @TODO: implement me
    }
}
