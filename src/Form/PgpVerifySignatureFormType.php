<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PgpVerifySignatureFormType extends AbstractType
{
    private const PGP_BODY_PATTERN = '[a-zA-Z0-9\\/+=\\s]+';
    private const PGP_PUBLIC_KEY_PATTERN = '#^-----BEGIN PGP PUBLIC KEY BLOCK-----\r?\n' . self::PGP_BODY_PATTERN . '\r?\n-----END PGP PUBLIC KEY BLOCK-----$#s';
    private const PGP_MESSAGE_PATTERN = '#^-----BEGIN PGP MESSAGE-----\r?\n' . self::PGP_BODY_PATTERN . '\r?\n-----END PGP MESSAGE-----$#s';
    private const PGP_SIGNATURE_PATTERN = '#^-----BEGIN PGP SIGNATURE-----\r?\n' . self::PGP_BODY_PATTERN . '\r?\n-----END PGP SIGNATURE-----$#s';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'default_public_key' => null,
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('public_key', TextareaType::class, [
                'label' => 'Public Key (Signing Key)',
                'data' => $options['default_public_key'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'data-bs-toggle' => 'tooltip',
                    'data-bs-placement' => 'top',
                    'title' => 'Paste the PGP public key used for signing. Defaults to server\'s key'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'The public key field cannot be empty'
                    ]),
                    new Assert\Regex([
                        'pattern' => self::PGP_PUBLIC_KEY_PATTERN,
                        'message' => 'Invalid PGP public key format. Ensure it matches the proper header, body, and footer format.'
                    ]),
                ],
                'trim' => true,
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Encrypted Message',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 8,
                    'data-bs-toggle' => 'tooltip',
                    'data-bs-placement' => 'top',
                    'title' => 'Paste the encrypted PGP message here',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'The encrypted message field cannot be empty',
                    ]),
                    new Assert\Regex([
                        'pattern' => self::PGP_MESSAGE_PATTERN,
                        'message' => 'Invalid PGP message format. Ensure it matches the expected header, body, and footer format.',
                    ]),
                ],
                'trim' => true,
            ])
            ->add('signature', TextareaType::class, [
                'label' => 'Message Signature',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'data-bs-toggle' => 'tooltip',
                    'data-bs-placement' => 'top',
                    'title' => 'Paste the PGP signature for the message',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'The signature field cannot be empty',
                    ]),
                    new Assert\Regex([
                        'pattern' => self::PGP_SIGNATURE_PATTERN,
                        'message' => 'Invalid PGP signature format. Ensure it matches the expected header, body, and footer format.',
                    ]),
                ],
                'trim' => true,
            ])
            ->add('verifySignaturePage', SubmitType::class, [
                'label' => 'Verify Signature',
                'attr' => [
                    'class' => 'btn btn-primary mt-3',
                    'data-bs-toggle' => 'tooltip',
                    'data-bs-placement' => 'top',
                    'title' => 'Verify if this message was authentically signed using the provided public key',
                ]
            ]);
    }
}
