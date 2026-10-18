<?php

namespace App\Controller\Admin;

use App\Entity\DepotClosure;
use App\Entity\Slot;
use App\Form\DepotClosureType;
use App\Form\SlotType;
use App\Repository\DepotClosureRepository;
use App\Repository\SlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Réglages → Créneaux au dépôt (story 3.3) : plages d'ouverture par jour et fermetures exceptionnelles. */
#[Route('/admin/reglages/creneaux')]
final class SlotController extends AbstractController
{
    public function __construct(
        private readonly SlotRepository $slots,
        private readonly DepotClosureRepository $closures,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_slot_index', methods: ['GET'])]
    public function index(): Response
    {
        $days = array_fill_keys(array_keys(Slot::WEEKDAYS), []);
        foreach ($this->slots->findBy([], ['startsAt' => 'ASC']) as $slot) {
            $days[$slot->getWeekday()][] = $slot;
        }

        return $this->render('admin/slot/index.html.twig', [
            'days' => $days,
            'closures' => $this->closures->findBy([], ['startsOn' => 'ASC']),
        ]);
    }

    #[Route('/ajouter', name: 'admin_slot_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $slot = new Slot();
        $slot->setWeekday(max(1, min(7, $request->query->getInt('jour', 1))));
        $form = $this->createForm(SlotType::class, $slot)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($this->slots->findBy(['weekday' => $slot->getWeekday()]) as $other) {
                if ($slot->overlaps($other)) {
                    $form->addError(new FormError(\sprintf('Cette plage chevauche celle de %s (%s). Choisissez d\'autres heures.', mb_strtolower(Slot::WEEKDAYS[$slot->getWeekday()]), $other->label())));
                }
            }
            if ($form->isValid()) {
                $this->em->persist($slot);

                return $this->saved('Plage ajoutée.');
            }
        }

        return $this->form($form, 'Ajouter une plage', 'Le dépôt est ouvert ce jour-là pendant cette plage, chaque semaine.');
    }

    #[Route('/{id}/retirer', name: 'admin_slot_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Slot $slot, Request $request): Response
    {
        return $this->removeEntity($slot, 'slot'.$slot->getId(), $request, 'Plage retirée.');
    }

    #[Route('/fermetures/ajouter', name: 'admin_closure_new', methods: ['GET', 'POST'])]
    public function newClosure(Request $request): Response
    {
        $closure = new DepotClosure();
        $form = $this->createForm(DepotClosureType::class, $closure)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($closure);

            return $this->saved('Fermeture ajoutée.');
        }

        return $this->form($form, 'Ajouter une fermeture', 'Aucun créneau n\'est proposé ces jours-là, même s\'ils ont des plages d\'ouverture.');
    }

    #[Route('/fermetures/{id}/retirer', name: 'admin_closure_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeClosure(DepotClosure $closure, Request $request): Response
    {
        return $this->removeEntity($closure, 'closure'.$closure->getId(), $request, 'Fermeture retirée.');
    }

    private function form(FormInterface $form, string $title, string $intro): Response
    {
        return $this->render('admin/settings/form.html.twig', ['form' => $form, 'title' => $title, 'intro' => $intro, 'back' => 'admin_slot_index', 'back_label' => 'Créneaux au dépôt']);
    }

    private function saved(string $message): Response
    {
        $this->em->flush();
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_slot_index');
    }

    private function removeEntity(object $entity, string $tokenId, Request $request, string $message): Response
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Session expirée, réessayez.');

            return $this->redirectToRoute('admin_slot_index');
        }
        $this->em->remove($entity);

        return $this->saved($message);
    }
}
