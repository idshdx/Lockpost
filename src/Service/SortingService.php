<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;
use ReflectionClass;

class SortingService
{
    private const BUBBLE = 'bubble';
    private const BUCKET = 'bucket';
    private const COUNTING = 'counting';
    private const INSERTION = 'insertion';
    private const FLIPPING = 'flipping';
    private const HEAP = 'heap';
    private const MERGE = 'merge';
    private const QUICK = 'quick';
    private const RADIX = 'radix';
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

    private function bucketSort(array $arrayToSort): array
    {
        $bucketCount = count($arrayToSort);

        $minValue = min($arrayToSort);
        $maxValue = max($arrayToSort);

        $bucketSize = ceil(($maxValue - $minValue + 1) / $bucketCount);

        // Initialize an array of empty buckets
        $buckets = array_fill(0, $bucketCount, []);

        // Distribute the elements from the input array into appropriate buckets
        foreach ($arrayToSort as $value) {
            // Determine the index of the bucket to which the element belongs
            $index = floor(($value - $minValue) / $bucketSize);

            // Ensure the index does not exceed the number of available buckets
            if ($index >= $bucketCount) {
                $index = $bucketCount - 1;
            }

            // Place the element into the corresponding bucket
            $buckets[$index][] = $value;
        }

        // Sort each individual bucket using the Insertion Sort algorithm
        foreach ($buckets as &$bucket) {
            $bucket = $this->insertionSort($bucket);
        }

        // Merge all the buckets to form the final sorted array
        $result = [];
        unset($bucket);
        foreach ($buckets as $bucket) {
            $result = array_merge($result, $bucket);
        }

        return $result;
    }

    /**
     * @throws AppException
     */
    private function radixSort(array $arrayToSort): array
    {
        foreach ($arrayToSort as $item) {
            if ($item < 0) {
                throw new AppException("Algoritmul de sortare Radix Sort acceptă doar numere naturale (întregi pozitive sau zero).");
            }
        }

        // Determine the number of digits in the largest number
        $maxDigits = $this->getMaxDigits($arrayToSort);

        // Apply Radix Sort for each digit position, from least significant to most significant
        for ($i = 1; $i <= $maxDigits; $i++) {
            $arrayToSort = $this->bucketSortOnDigitI($arrayToSort, $i);
        }

        return $arrayToSort;
    }

    /**
     * Get the number of digits in the largest number in the array
     */
    function getMaxDigits(array $arrayToSort): int
    {
        $max = max($arrayToSort);
        $maxDigits = 0;

        // Calculate the number of digits in the largest number
        while ($max > 0) {
            $max = (int)($max / 10);
            $maxDigits++;
        }

        return $maxDigits;
    }

    /**
     * (For RadixSort) Distribute elements into buckets based on the digit at a specific position
     */
    function bucketSortOnDigitI(array $arrayToSort, int $digitPosition): array
    {
        // Initialize an array of 10 buckets (for digits 0 to 9)
        $buckets = array_fill(0, 10, []);

        // Distribute elements into the corresponding buckets based on the current digit
        foreach ($arrayToSort as $number) {
            $digit = $this->getDigitAtPosition($number, $digitPosition);
            $buckets[$digit][] = $number;
        }

        // Merge the elements back from buckets to form the array in sorted order
        $arrayToSort = [];
        foreach ($buckets as $bucket) {
            $arrayToSort = array_merge($arrayToSort, $bucket);
        }

        return $arrayToSort;
    }

    /**
     * Get the digit at a specific position in a number
     */
    function getDigitAtPosition(int $number, int $position): int
    {
        // Obtain the digit at the specified position
        return (int)(($number / pow(10, $position - 1)) % 10);
    }

    private function flippingSort(array $arrayToSort): array
    {
        $count = count($arrayToSort);

        // Iterate from the full array size down to 2
        for ($currentSize = $count; $currentSize > 1; $currentSize--) {
            // Find the index of the largest element in the first $currentSize elements
            $maxIndex = $this->findMax($arrayToSort, $currentSize);

            // If the largest element is not already at the end of the current segment
            if ($maxIndex != $currentSize - 1) {
                // Move the largest element to the beginning of the array segment
                $this->flip($arrayToSort, $maxIndex);
                // Then place it at the end of the segment by flipping the current size's end
                $this->flip($arrayToSort, $currentSize - 1);
            }
        }

        return $arrayToSort;
    }

    /**
     * Flip elements in the array between two indices
     */
    private function flip(array &$array, int $index): void
    {
        $start = 0;

        // Swap elements from the start-up to the given index
        while ($start < $index) {
            $temp = $array[$start];
            $array[$start] = $array[$index];
            $array[$index] = $temp;

            $start++;
            $index--;
        }
    }

    /**
     * Find the index of the largest element in the first $length elements of the array
     */
    private function findMax(array $array, int $length): int
    {
        $maxIndex = 0;

        // Iterate through the array segment to find the maximum element's index
        for ($i = 0; $i < $length; $i++) {
            if ($array[$i] > $array[$maxIndex]) {
                $maxIndex = $i;
            }
        }

        return $maxIndex;
    }

    private function heapSort(array $arrayToSort): array
    {
        $heapSize = count($arrayToSort);

        // Build the heap (rearranging the array to form a valid max-heap)
        for ($i = (int)($heapSize / 2) - 1; $i >= 0; $i--) {
            $this->heapify($arrayToSort, $heapSize, $i);
        }

        // Repeatedly extract the largest element from the heap and move it to the end of the array
        for ($i = $heapSize - 1; $i > 0; $i--) {
            // Swap the root (the largest element) with the last element in the heap
            $temp = $arrayToSort[0];
            $arrayToSort[0] = $arrayToSort[$i];
            $arrayToSort[$i] = $temp;

            // Restore the heap property for the remaining elements
            $this->heapify($arrayToSort, $i, 0);
        }

        return $arrayToSort;
    }

    /**
     * Transform a subtree into a max-heap with the root at the specified $root index
     */
    private function heapify(array &$arrayToSort, int $heapSize, int $root): void
    {
        $largest = $root;
        $leftChild = 2 * $root + 1;
        $rightChild = 2 * $root + 2;

        // Check if the left child exists and is greater than the root
        if ($leftChild < $heapSize && $arrayToSort[$leftChild] > $arrayToSort[$largest]) {
            $largest = $leftChild;
        }

        // Check if the right child exists and is greater than the current largest
        if ($rightChild < $heapSize && $arrayToSort[$rightChild] > $arrayToSort[$largest]) {
            $largest = $rightChild;
        }

        // If the largest node is not the root, swap and heapify recursively
        if ($largest != $root) {
            $temp = $arrayToSort[$root];
            $arrayToSort[$root] = $arrayToSort[$largest];
            $arrayToSort[$largest] = $temp;

            // Recursively heapify the affected subtree
            $this->heapify($arrayToSort, $heapSize, $largest);
        }
    }
}
