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
    // Allows standard OpenPGP armor body including header lines
    // (Version, Hash, Comment, etc.) and blank lines separating them.
    private const PGP_BODY_PATTERN = '[\w\s\/+=.:,;\-()\r\n]+';

    private const PGP_PUBLIC_KEY_PATTERN = '#^-----BEGIN PGP PUBLIC KEY BLOCK-----\r?\n' . self::PGP_BODY_PATTERN . '-----END PGP PUBLIC KEY BLOCK-----$#s';

    // Accepts both combined cleartext-signed (BEGIN PGP SIGNED MESSAGE)
    // and raw ciphertext-only (BEGIN PGP MESSAGE) blocks.
    private const PGP_SIGNED_MESSAGE_PATTERN = '#^-----BEGIN PGP (SIGNED MESSAGE|MESSAGE)-----\r?\n' . self::PGP_BODY_PATTERN . '-----END PGP (SIGNATURE|MESSAGE)-----$#s';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'default_public_key' => null,
            'required' => true
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'verify_signature_form';
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
                    new Assert\NotBlank(
                        message: 'The public key field cannot be empty'
                    ),
                    new Assert\Regex(
                        pattern: self::PGP_PUBLIC_KEY_PATTERN,
                        message: 'Invalid PGP public key format. Ensure it matches the proper header, body, and footer format.'
                    ),
                ],
                'trim' => true,
            ])
            ->add('signed_message', TextareaType::class, [
                'label' => 'Signed Message',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 12,
                    'data-bs-toggle' => 'tooltip',
                    'data-bs-placement' => 'top',
                    'title' => 'Paste the full PGP-signed message block here (-----BEGIN PGP SIGNED MESSAGE-----)',
                ],
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'The signed message field cannot be empty',
                    ),
                    new Assert\Regex(
                        pattern: self::PGP_SIGNED_MESSAGE_PATTERN,
                        message: 'Invalid PGP signed message format. Paste the full block starting with -----BEGIN PGP SIGNED MESSAGE----- or -----BEGIN PGP MESSAGE-----.',
                    ),
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
