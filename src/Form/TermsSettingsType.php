<?php

namespace App\Form;

use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Plus → Réglages → Conditions (story 3.2) : textes repris sur l'Encadré Conditions et sur chaque devis. */
final class TermsSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = [
            SettingsProvider::RETURN_TERMS => ['Règles de retour', 'Par exemple : matériel rendu complet, propre et rangé dans ses housses.'],
            SettingsProvider::PICKUP_TERMS => ['Retrait au dépôt', 'Par exemple : véhicule adapté, sangles, et quelqu\'un sur place pour charger.'],
            SettingsProvider::FUEL_TERMS => ['Révision du tarif de livraison', 'Par exemple : le tarif au kilomètre peut être revu selon le prix du carburant.'],
        ];
        foreach ($fields as $name => [$label, $help]) {
            $builder->add($name, TextareaType::class, [
                'label' => $label,
                'help' => $help,
                'empty_data' => '',
                'attr' => ['rows' => 4],
                'constraints' => [
                    new Assert\NotBlank(message: 'Ce texte apparaît sur chaque devis : il ne peut pas être vide.'),
                    new Assert\Length(max: 2000, maxMessage: 'Le texte ne doit pas dépasser {{ limit }} caractères.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_message' => 'Session expirée, réessayez.']);
    }
}
