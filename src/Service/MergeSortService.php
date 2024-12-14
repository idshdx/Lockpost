<?php

namespace App\Service;

class MergeSortService
{
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

    // The function to merge two sorted sublists
    public function merge(array $left, array $right): array
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
}
