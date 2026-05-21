<?php

namespace App\Form;

use App\Entity\CustomizationRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomizationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('productType', ChoiceType::class, [
                'label' => 'Choose what to customize',
                'choices' => [
                    'Tshirt' => 'T-shirt',
                    'Accessories (Tote Bag Only)' => 'Accessories',
                ],
                'expanded' => true,
            ])
            ->add('size', TextType::class, [
                'label' => 'Size or fit',
                'required' => false,
                'attr' => [
                    'placeholder' => 'S, M, L, oversized, adjustable...',
                ],
            ])
            ->add('placement', ChoiceType::class, [
                'label' => 'Fabric',
                'required' => false,
                'placeholder' => 'Choose fabric',
                'choices' => [
                    'Silk' => 'Silk',
                    'Cotton' => 'Cotton',
                    'Polyester' => 'Polyester',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Extra notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Deadline, budget, quantity, or any special request.',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomizationRequest::class,
        ]);
    }
}
