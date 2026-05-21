<?php

namespace App\Form;

use App\Entity\CustomizationRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomizationRequestStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'Pending' => CustomizationRequest::STATUS_PENDING,
                    'Reviewing' => CustomizationRequest::STATUS_REVIEWING,
                    'Approved' => CustomizationRequest::STATUS_APPROVED,
                    'Completed' => CustomizationRequest::STATUS_COMPLETED,
                    'Declined' => CustomizationRequest::STATUS_DECLINED,
                ],
            ])
            ->add('assignedTo', EntityType::class, [
                'class' => User::class,
                'choice_label' => fn (User $user): string => $user->getFullName() ?: $user->getUsername(),
                'query_builder' => fn (UserRepository $repository) => $repository->createQueryBuilder('u')
                    ->where('u.roles LIKE :staffRole')
                    ->setParameter('staffRole', '%ROLE_STAFF%')
                    ->orderBy('u.fullName', 'ASC'),
                'placeholder' => 'Unassigned',
                'required' => false,
            ])
            ->add('staffResponse', TextareaType::class, [
                'label' => 'Staff response',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Add quotation notes, production updates, or customer instructions.',
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
