<?php

namespace App\Form;

use App\Entity\Product;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Liste des produits par nom, avec catégorie et minimum ; les produits masqués sont signalés. `exclude` : produit à ne pas proposer. */
final class ProductChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Product::class,
            'placeholder' => 'Choisir…',
            'exclude' => null,
            'query_builder' => fn (Options $options) => function (EntityRepository $r) use ($options) {
                $qb = $r->createQueryBuilder('p')->addSelect('c')->join('p.category', 'c')->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');
                if (null !== $options['exclude']) {
                    $qb->andWhere('p.id != :exclude')->setParameter('exclude', $options['exclude']);
                }

                return $qb;
            },
            'choice_label' => fn (Product $p) => \sprintf('%s — %s%s%s', $p->getName(), $p->getCategory()->getName(),
                $p->getMinQty() > 1 ? \sprintf(' (minimum %d)', $p->getMinQty()) : '',
                $p->isVisible() && $p->getCategory()->isVisible() ? '' : ' — masqué'),
            'invalid_message' => 'Choisir un produit de la liste.',
        ]);
    }

    public function getParent(): string
    {
        return EntityType::class;
    }
}
