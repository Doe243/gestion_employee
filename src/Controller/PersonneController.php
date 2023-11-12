<?php

namespace App\Controller;

use App\Entity\Personne;
use App\Entity\Emploi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use DateTime;

class PersonneController extends AbstractController
{
    /**
     * @Route("/personne", name="create_personne", methods={"POST"})
     */
    public function createPersonne(Request $request, ValidatorInterface $validator, EntityManagerInterface $em): Response
    {
        $entityManager = $em()->getManager();
        $data = json_decode($request->getContent(), true);

        // Créer et configurer l'entité Personne
        $personne = new Personne();
        $personne->setNom($data['nom']);
        $personne->setPrenom($data['prenom']);
        $personne->setDateNaissance(new DateTime($data['dateNaissance']));

        // Validation de l'entité
        $errors = $validator->validate($personne);
        if (count($errors) > 0) {
            return $this->json((string) $errors, 400);
        }

        // Vérifier l'âge
        $today = new DateTime();
        $age = $today->diff($personne->getDateNaissance())->y;
        if ($age > 150) {
            return $this->json("L'âge doit être inférieur à 150 ans", 400);
        }

        // Sauvegarder l'entité
        $entityManager->persist($personne);
        $entityManager->flush();

        return $this->json(['message' => 'Personne créée avec succès'], 201);
    }

    /**
     * @Route("/personne/{id}/emploi", name="add_emploi", methods={"POST"})
     */
    public function addEmploi(Request $request, $id, EntityManagerInterface $em): Response
    {
        $entityManager = $em->getRepository(Personne::class);
        $personne = $entityManager->find($id);

        if (!$personne) {
            return $this->json(['message' => 'Personne non trouvée'], 404);
        }

        $data = json_decode($request->getContent(), true);

        $emploi = new Emploi();
        $emploi->setNomEntreprise($data['nomEntreprise']);
        $emploi->setPoste($data['poste']);
        $emploi->setDateDebut(new \DateTime($data['dateDebut']));
        $emploi->setDateFin(isset($data['dateFin']) ? new \DateTime($data['dateFin']) : null);
        $emploi->setPersonne($personne);

        // Gestion des chevauchements de dates
        foreach ($personne->getEmplois() as $existingEmploi) {
            if ($existingEmploi->getDateDebut() < $emploi->getDateFin() &&
                $existingEmploi->getDateFin() > $emploi->getDateDebut()) {
                return $this->json(['message' => 'Chevauchement de dates détecté'], 400);
            }
        }

        $entityManager->persist($emploi);
        $entityManager->flush();

        return $this->json(['message' => 'Emploi ajouté avec succès'], 201);
    }


    /**
     * @Route("/personnes", name="list_personnes", methods={"GET"})
     */
    public function listPersonnes(EntityManagerInterface $em): Response
    {
        $personnes = $em->getRepository(Personne::class)->findBy([], ['nom' => 'ASC']);

        $personnesData = array_map(function ($personne) {
            $today = new \DateTime();

            // Calculer l'âge
            $age = $today->diff($personne->getDateNaissance())->y;

            // Renvoyer les emplois actuels
            $emploisActuels = array_filter($personne->getEmplois()->toArray(), function ($emploi) use ($today) {
                return $emploi->getDateFin() === null || $emploi->getDateFin() > $today;
            });

            $emploisActuelsFormat = array_map(function ($emploi) {
                return [
                    'nomEntreprise' => $emploi->getNomEntreprise(),
                    'poste' => $emploi->getPoste(),
                    'dateDebut' => $emploi->getDateDebut()->format('Y-m-d'),
                    'dateFin' => $emploi->getDateFin() ? $emploi->getDateFin()->format('Y-m-d') : null
                ];
            }, $emploisActuels);

            return [
                'nom' => $personne->getNom(),
                'prenom' => $personne->getPrenom(),
                'age' => $age,
                'emploisActuels' => $emploisActuelsFormat
            ];
        }, $personnes);

        return $this->json($personnesData);
    }


    /**
     * @Route("/personnes/entreprise/{nomEntreprise}", name="personnes_par_entreprise", methods={"GET"})
     */
    public function listPersonnesParEntreprise($nomEntreprise, EntityManagerInterface $em): Response
    {
        $personnes = $em->getRepository(Personne::class)->findParEntreprise($nomEntreprise);

        // Transformer les résultats en tableau
        $personnesData = array_map(function ($personne) {
            return [
                'id' => $personne->getId(),
                'nom' => $personne->getNom(),
                'prenom' => $personne->getPrenom(),
                'dateNaissance' => $personne->getDateNaissance()->format('Y-m-d'),
                'emplois' => array_map(function ($emploi) {
                    return [
                        'nomEntreprise' => $emploi->getNomEntreprise(),
                        'poste' => $emploi->getPoste(),
                        'dateDebut' => $emploi->getDateDebut()->format('Y-m-d'),
                        'dateFin' => $emploi->getDateFin() ? $emploi->getDateFin()->format('Y-m-d') : null
                    ];
                }, $personne->getEmplois()->toArray())
            ];
        }, $personnes);

        return $this->json($personnesData);
    }


    /**
     * @Route("/personne/{id}/emplois", name="list_emplois_par_date", methods={"GET"})
     */
    public function listEmploisParDate(Request $request, $id, EntityManagerInterface $em): Response
    {
        $entityManager = $em()->getManager();
        $personne = $entityManager->getRepository(Personne::class)->find($id);

        if (!$personne) {
            return $this->json(['message' => 'Personne non trouvée'], 404);
        }

        $dateDebut = new \DateTime($request->query->get('dateDebut'));
        $dateFin = new \DateTime($request->query->get('dateFin'));

        $emplois = $entityManager->getRepository(Emploi::class)->findByDateRangeAndPersonne($dateDebut, $dateFin, $personne);

        $emploisData = array_map(function ($emploi) {
            return [
                'nomEntreprise' => $emploi->getNomEntreprise(),
                'poste' => $emploi->getPoste(),
                'dateDebut' => $emploi->getDateDebut()->format('Y-m-d'),
                'dateFin' => $emploi->getDateFin() ? $emploi->getDateFin()->format('Y-m-d') : null
            ];
        }, $emplois);

        return $this->json($emploisData);
    }
}
