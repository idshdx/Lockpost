<?php

namespace App\Service;

use Exception;
use ReflectionClass;

class SortingService
{
    private const MERGE = 'merge';
    private const QUICK = 'quick';
    private const BUBBLE = 'bubble';
    private const INSERTION = 'insertion';
    private const SELECTION = 'selection';

    public function getSortingMethodsArray(): array
    {
        return array_values((new ReflectionClass(self::class))->getConstants());
    }

    /**
     * @throws Exception
     */
    public function sort(array $arrayToSort, string $sortingMethod): array
    {
        $methodName = $sortingMethod . 'Sort';

        if (method_exists($this, $methodName)) {
            return $this->$methodName($arrayToSort);
        }

        throw new Exception("Metoda de sortare {$methodName} nu exista!");
    }

    // The MergeSort function itself
    public function mergeSort(array $arrayToSort): array
    {
        $length = count($arrayToSort);
        if ($length < 2) {
            return $arrayToSort;
        }

        $middle = (int)($length / 2);
        $left = array_slice($arrayToSort, 0, $middle);
        $right = array_slice($arrayToSort, $middle);

        $left = $this->mergeSort($left);
        $right = $this->mergeSort($right);

        return $this->merge($left, $right);
    }

    // The function to merge two sorted sublist
    private function merge(array $left, array $right): array
    {
        $result = [];
        $leftIndex = $rightIndex = 0;

        while ($leftIndex < count($left) && $rightIndex < count($right)) {
            if ($left[$leftIndex] < $right[$rightIndex]) {
                $result[] = $left[$leftIndex];
                $leftIndex++;
            } else {
                $result[] = $right[$rightIndex];
                $rightIndex++;
            }
        }

        return array_merge($result, array_slice($left, $leftIndex), array_slice($right, $rightIndex));
    }

    private function quickSort($arrayToSort): array
    {
        $left = $right = [];

        if (count($arrayToSort) < 2) {
            return $arrayToSort;
        }

        $pivot = end($arrayToSort);
        array_pop($arrayToSort);

        foreach ($arrayToSort as $item) {
            if ($item < $pivot) {
                $left[] = $item;
            } else {
                $right[] = $item;
            }
        }

        return array_merge($this->quickSort($left), [$pivot], $this->quickSort($right));
    }

    private function insertionSort($arrayToSort)
    {
        $n = count($arrayToSort);

        for($i = 1; $i < $n; $i++) {
            $key = $arrayToSort[$i];
            $j = $i - 1;

            while ($j >= 0 && $arrayToSort[$j] > $key) {
                $arrayToSort[$j + 1] = $arrayToSort[$j];
                $j = $j - 1;
            }
            $arrayToSort[$j + 1] = $key;
        }
        return $arrayToSort;
    }
}
