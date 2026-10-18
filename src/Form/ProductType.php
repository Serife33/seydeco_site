<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\EventType;
use App\Entity\Product;
use App\Entity\ProductColor;
use App\Entity\Style;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $integer = fn (string $label, string $example, array $extra = []) => $extra + [
            'label' => $label,
            'invalid_message' => "Saisir un nombre entier, par exemple $example.",
            // Format français : « 1,5 » est refusé au lieu d'être lu comme 15
            'grouping' => true,
            'attr' => ['inputmode' => 'numeric'],
        ];
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
                // Défauts casse/perte/consignes, recopiés dans les champs vides à la création (contrôleur « category-defaults »)
                'choice_attr' => fn (Category $c) => [
                    'data-breakage' => self::euros($c->getBreakagePrice()),
                    'data-loss' => self::euros($c->getLossPrice()),
                    'data-instructions' => $c->getReturnInstructions() ?? '',
                ],
            ])
            ->add('dimensions', TextType::class, ['label' => 'Dimensions', 'required' => false, 'help' => 'Par exemple : 45 × 50 × 90 cm'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('seats', IntegerType::class, $integer('Nombre de places (facultatif)', '8', [
                'required' => false,
                // Sert au calcul de la remise tables avec chaises : un seul nombre
                'invalid_message' => 'Saisir un seul nombre, par exemple 10 (pas « 8-10 »).',
                'help' => 'Un seul nombre : le maximum de personnes autour de la table.',
            ]))
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
            ->add('stock', IntegerType::class, $integer('Stock', '800'))
            ->add('priceTiers', CollectionType::class, self::collection(PriceTierType::class, 'Paliers de prix'))
            ->add('priceWithChairs', AdminMoneyType::class, [
                'label' => 'Prix avec chaises (facultatif)',
                'help' => 'Tables seulement : prix par table quand assez de chaises sont louées pour garnir toutes les tables. Laisser vide si pas de prix réduit.',
            ])
            ->add('breakagePrice', AdminMoneyType::class, ['label' => 'Prix de casse (par unité)'])
            ->add('lossPrice', AdminMoneyType::class, ['label' => 'Prix de perte (par unité)'])
            ->add('returnInstructions', TextareaType::class, ['label' => 'Consignes de retour', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('colors', CollectionType::class, self::collection(ProductColorType::class, 'Coloris'))
            ->add('photos', CollectionType::class, self::collection(ProductPhotoType::class, 'Photos', [
                // Seuls les coloris déjà enregistrés peuvent être rattachés à une photo
                'colors' => $options['data']?->getColors()->filter(fn (ProductColor $c) => null !== $c->getId())->getValues() ?? [],
            ]))
            ->add('relatedProducts', CollectionType::class, self::collection(RelatedProductType::class, 'Produits associés', [
                // Le produit lui-même n'est pas proposé
                'exclude' => $options['data']?->getId(),
            ]));
        self::addVideoFields($builder);
    }

    /** Vidéo facultative (produit, réalisation) : fichier MP4 ou lien, miniature facultative. */
    public static function addVideoFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('videoUpload', FileType::class, [
                'label' => 'Vidéo MP4 (50 Mo maximum)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'video/mp4'],
                'constraints' => [new File(
                    maxSize: '50M',
                    mimeTypes: ['video/mp4'],
                    maxSizeMessage: 'Vidéo trop lourde (50 Mo maximum).',
                    uploadIniSizeErrorMessage: 'Vidéo trop lourde (50 Mo maximum).',
                    mimeTypesMessage: 'Format non accepté : choisir une vidéo MP4.',
                    uploadErrorMessage: 'La vidéo n\'a pas pu être envoyée, réessayez.',
                )],
            ])
            ->add('removeVideoFile', CheckboxType::class, ['label' => 'Retirer la vidéo MP4', 'mapped' => false, 'required' => false])
            ->add('videoUrl', TextType::class, [
                'label' => 'Ou lien vers la vidéo',
                'required' => false,
                'help' => 'YouTube, Instagram ou TikTok uniquement.',
                'attr' => ['inputmode' => 'url'],
            ])
            ->add('videoThumbnailUpload', FileType::class, [
                'label' => 'Miniature de la vidéo (facultative)',
                'help' => 'Sans miniature, la photo principale est utilisée.',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif'],
                'constraints' => [new File(
                    maxSize: '10M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxSizeMessage: ProductPhotoType::TOO_BIG,
                    uploadIniSizeErrorMessage: ProductPhotoType::TOO_BIG,
                    mimeTypesMessage: 'Format non accepté : choisir une photo JPEG, PNG ou WebP.',
                    uploadErrorMessage: 'La photo n\'a pas pu être envoyée, réessayez.',
                )],
            ])
            ->add('removeVideoThumbnail', CheckboxType::class, ['label' => 'Retirer la miniature', 'mapped' => false, 'required' => false])
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $hasFile = null !== $form->get('videoUpload')->getData()
                    || (null !== $event->getData()->getVideoFile() && !$form->get('removeVideoFile')->getData());
                if ($hasFile && null !== $event->getData()->getVideoUrl()) {
                    $form->get('videoUrl')->addError(new FormError('Choisir une vidéo MP4 ou un lien, pas les deux.'));
                }
            });
    }

    /** Centimes → « 45,00 », comme l'affiche le champ montant. */
    private static function euros(?int $cents): string
    {
        return null === $cents ? '' : number_format($cents / 100, 2, ',', '');
    }

    /** Lignes ajoutables / retirables (contrôleur Stimulus « collection »). */
    public static function collection(string $entryType, string $label, array $entryOptions = []): array
    {
        return [
            'label' => $label,
            'entry_type' => $entryType,
            'entry_options' => ['label' => false] + $entryOptions,
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            // Erreurs de la liste entière (ex. paliers non croissants) affichées sous la liste, pas en haut du formulaire
            'error_bubbling' => false,
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
