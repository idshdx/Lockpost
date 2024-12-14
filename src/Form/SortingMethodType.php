<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class SortingMethodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('arrayToSort', TextType::class, [
                'constraints' => [
                    new NotBlank([
                        'message' => "Acest camp nu poate fi gol!",
                    ]),
                    new Regex([
                        'pattern' => '/^(-?\d+(\.\d+)?)(,\s*-?\d+(\.\d+)?)*$/',
                        'message' => "Introduceti un sir de numere reale separate prin virgula!"
                    ]),
                ],
            ]);
    }

    // public function configureOptions(OptionsResolver $resolver): void
    // {
    //     $resolver->setDefaults([
    //         'data_class' => null,
    //     ]);
    // }
}
