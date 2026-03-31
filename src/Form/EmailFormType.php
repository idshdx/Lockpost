<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class EmailFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Where to receive the message',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter your email address'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Please enter your email address']),
                    new Email(['message' => 'Please enter a valid email address'])
                ]
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Generate',
                'attr' => ['class' => 'btn btn-primary mt-3']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => true, // Default to required
            'attr' => ['class' => 'form'], // Add your preferred default attributes
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'email_form'; // Explicitly set the form name
    }

}
