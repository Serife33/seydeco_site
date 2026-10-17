<?php

namespace App\Form;

use App\Entity\FaqItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FaqItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextType::class, ['label' => 'Question'])
            ->add('answer', MarkdownType::class, ['label' => 'Réponse', 'attr' => ['rows' => 6]])
            ->add('theme', TextType::class, [
                'label' => 'Thème (facultatif)',
                'required' => false,
                'help' => 'Par exemple : Livraison, Caution, Retour. Les questions d\'un même thème sont regroupées.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FaqItem::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
