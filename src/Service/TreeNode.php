<?php

namespace App\Service;

class TreeNode
{
    public float $value;
    public ?TreeNode $left = null;
    public ?TreeNode $right = null;

    public function __construct(float $value)
    {
        $this->value = $value;
    }

    public function insert(float $value): void
    {
        if ($value < $this->value) {
            if ($this->left === null) {
                $this->left = new TreeNode($value);
            } else {
                $this->left->insert($value);
            }
        } else {
            if ($this->right === null) {
                $this->right = new TreeNode($value);
            } else {
                $this->right->insert($value);
            }
        }
    }

    public function inOrderTraversal(array &$sortedArray): void
    {
        $this->left?->inOrderTraversal($sortedArray);
        $sortedArray[] = $this->value;
        $this->right?->inOrderTraversal($sortedArray);
    }
}