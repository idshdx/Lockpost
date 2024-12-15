<?php

namespace App\Tests\Service;

use App\Service\SortingService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SortingServiceTest extends KernelTestCase
{
    /**
     * @throws Exception
     */
    public function testSort()
    {
        $container = static::getContainer();

        $sortingService = $container->get(SortingService::class);

        $inputArray = [55, 44, 43, 42, 22, 11, 10];
        $expectedArray = $inputArray;
        sort($expectedArray);

        $sortingMethodsArray = $sortingService->getSortingMethodsArray();

        foreach ($sortingMethodsArray as $sortingMethodName) {
            $returnedSortingServiceArray = $sortingService->sort($inputArray, $sortingMethodName);
            $this->assertEquals($expectedArray, $returnedSortingServiceArray, "Metoda de sortare $sortingMethodName nu e corecta!");
        }
    }
}
