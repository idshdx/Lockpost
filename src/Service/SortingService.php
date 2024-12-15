<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;
use ReflectionClass;

class SortingService
{
    private const BUBBLE = 'bubble';
    private const COUNTING = 'counting';
    private const INSERTION = 'insertion';
    private const MERGE = 'merge';
    private const SELECTION = 'selection';
    private const QUICK = 'quick';

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

        throw new AppException("Metoda de sortare {$methodName} nu exista!");
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

    private function insertionSort(array $arrayToSort): array
    {
        $n = count($arrayToSort);

        for ($i = 1; $i < $n; $i++) {
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

    private function selectionSort(array $arrayToSort): array
    {
        $n = count($arrayToSort);

        for ($i = 0; $i < $n - 1; $i++) {
            $minIndex = $i;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($arrayToSort[$j] < $arrayToSort[$minIndex]) {
                    $minIndex = $j;
                }
            }

            if ($minIndex != $i) {
                $temp = $arrayToSort[$i];
                $arrayToSort[$i] = $arrayToSort[$minIndex];
                $arrayToSort[$minIndex] = $temp;
            }
        }

        return $arrayToSort;
    }

    private function bubbleSort(array $arrayToSort): array
    {
        $n = count($arrayToSort);

        do {
            $swapped = false;

            for ($i = 1; $i < $n; $i++) {
                if ($arrayToSort[$i - 1] > $arrayToSort[$i]) {
                    $temp = $arrayToSort[$i - 1];
                    $arrayToSort[$i - 1] = $arrayToSort[$i];
                    $arrayToSort[$i] = $temp;

                    $swapped = true;
                }
            }

        } while ($swapped);

        return $arrayToSort;
    }

    /**
     * @throws AppException
     */
    private function countingSort(array $arrayToSort): array
    {
        if (empty($arrayToSort)) {
            return [];
        }

        $maxValue = max($arrayToSort);

        // Initialize the count array with zeros
        $count = array_fill(0, $maxValue + 1, 0);

        // Count occurrences of each value in the input array
        foreach ($arrayToSort as $value) {
            if ($value < 0) {
                throw new AppException("Algoritmul de sortare Counting Sort acceptă doar numere naturale (întregi pozitive sau zero).");
            }

            $count[$value]++;
        }

        // Accumulate the count array to store the positions of elements
        for ($i = 1; $i <= $maxValue; $i++) {
            $count[$i] += $count[$i - 1];
        }

        // Initialize the sorted array
        $sorted = array_fill(0, count($arrayToSort), null);

        // Build the sorted array in reverse order for stability
        for ($i = count($arrayToSort) - 1; $i >= 0; $i--) {
            $value = $arrayToSort[$i];
            $position = $count[$value] - 1; // Determine the position in the sorted array
            $sorted[$position] = $value;
            $count[$value]--; // Decrement the count for the value
        }

        return $sorted;
    }
}
