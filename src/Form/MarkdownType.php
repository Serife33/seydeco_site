<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Champ « texte mis en forme simple » : zone de texte + barre Titre · Gras · Liste · Lien (thème admin, bloc markdown_widget). */
final class MarkdownType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['rows' => 12],
            'help' => 'Mise en forme : ## Titre, **gras**, - élément de liste, [texte du lien](https://…). Ligne vide = nouveau paragraphe. Le HTML n\'est pas accepté.',
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'markdown';
    }
}
