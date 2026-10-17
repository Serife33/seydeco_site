<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\EventType;
use App\Entity\Product;
use App\Entity\Style;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $integer = fn (string $label, string $example, array $extra = []) => [
            'label' => $label,
            'invalid_message' => "Saisir un nombre entier, par exemple $example.",
            // Format français : « 1,5 » est refusé au lieu d'être lu comme 15
            'grouping' => true,
            'attr' => ['inputmode' => 'numeric'],
        ] + $extra;
        $byName = fn (EntityRepository $r) => $r->createQueryBuilder('x')->orderBy('x.name', 'ASC');

        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'placeholder' => 'Choisir…',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')->orderBy('c.position', 'ASC'),
                // Délai de remise en état : lecture seule, il appartient à la catégorie
                'choice_label' => fn (Category $c) => \sprintf('%s (remise en état : %d h)', $c->getName(), $c->getRefurbishDelay()),
            ])
            ->add('dimensions', TextType::class, ['label' => 'Dimensions', 'required' => false, 'help' => 'Par exemple : 45 × 50 × 90 cm'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('seats', IntegerType::class, $integer('Nombre de places (facultatif)', '8', ['required' => false]))
            ->add('styles', EntityType::class, [
                'label' => 'Styles',
                'class' => Style::class,
                'choice_label' => 'name',
                'query_builder' => $byName,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('newStyle', TextType::class, [
                'label' => 'Ajouter un style',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 100, maxMessage: 'Le style ne doit pas dépasser {{ limit }} caractères.')],
            ])
            ->add('eventTypes', EntityType::class, [
                'label' => 'Types d\'événement',
                'class' => EventType::class,
                'choice_label' => 'name',
                'query_builder' => $byName,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('newEventType', TextType::class, [
                'label' => 'Ajouter un type d\'événement',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 100, maxMessage: 'Le type ne doit pas dépasser {{ limit }} caractères.')],
            ])
            ->add('minQty', IntegerType::class, $integer('Quantité minimale', '15', ['help' => '1 si aucun minimum.']))
            ->add('stock', IntegerType::class, $integer('Stock', '800'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
