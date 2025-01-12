<?php

namespace App\Service;

class SearchingService
{
    public function sequentialSearch(array $array, int $needle): int
    {
        foreach ($array as $position => $item) {
            if ($item == $needle) {
                return $position;
            }
        }

        return -1;
    }

    public function binarySearch(array $array, int $needle): int
    {
        $low = 0;
        $high = count($array) - 1;

        while ($low <= $high) {
            $mid = $low + (int)(($high - $low) / 2);

            if ($array[$mid] < $needle) {
                $low = $mid + 1;
            } elseif ($array[$mid] > $needle) {
                $high = $mid - 1;
            } else {
                return $mid;
            }
        }

        return -1;
    }
}