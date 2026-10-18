<?php

namespace App\Form;

use App\Entity\EventType;
use App\Entity\Realisation;
use App\Entity\RealisationPhoto;
use App\Entity\Venue;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Photos et vidéo déposées comme pour un produit (ProductPhotoType, ProductType::addVideoFields). */
final class RealisationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Titre', 'help' => 'Par exemple : Mariage de Léa et Karim.'])
            ->add('eventDate', DateType::class, [
                'label' => 'Date de l\'événement',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Saisir une date valide.',
            ])
            ->add('eventType', EntityType::class, [
                'label' => 'Type d\'événement',
                'class' => EventType::class,
                'choice_label' => 'name',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('x')->orderBy('x.name', 'ASC'),
                'placeholder' => 'Aucun en particulier',
                'required' => false,
                'help' => 'Même liste que les produits.',
            ])
            ->add('venue', EntityType::class, [
                'label' => 'Lieu partenaire (facultatif)',
                'class' => Venue::class,
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('v')->orderBy('v.name', 'ASC'),
                'choice_label' => fn (Venue $v) => $v->getName().' — '.$v->getCity().($v->isVisible() ? '' : ' — masqué'),
                'placeholder' => 'Aucun',
                'required' => false,
                'help' => 'Si un lieu est choisi, son nom et sa ville sont repris automatiquement.',
            ])
            ->add('venueName', TextType::class, ['label' => 'Nom du lieu', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('text', TextareaType::class, ['label' => 'Texte', 'required' => false, 'attr' => ['rows' => 6]])
            ->add('photos', CollectionType::class, ProductType::collection(ProductPhotoType::class, 'Photos', ['data_class' => RealisationPhoto::class]))
            // Même liste de choix que « Souvent loué avec » ; ligne laissée sur « Choisir… » : ignorée
            ->add('products', CollectionType::class, ['entry_options' => ['label' => 'Produit utilisé'], 'delete_empty' => true] + ProductType::collection(ProductChoiceType::class, 'Produits utilisés'))
            // Avant la validation : le nom et la ville du lieu partenaire remplacent ceux saisis
            ->addEventListener(FormEvents::SUBMIT, fn (FormEvent $event) => $event->getData()->applyVenue());
        ProductType::addVideoFields($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Realisation::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
