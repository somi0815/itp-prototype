<?php

namespace App\Controller;


use App\Entity\ChangeSet;
use App\Entity\Contestant;
use App\Entity\Official;
use App\Entity\Registration;
use App\Form\ChangeSetType;
use App\Form\LoginType;
use App\Form\ForgotPasswordType;
use App\Repository\ChangeSetRepository;
use App\Repository\ContestantsRepository;
use App\Repository\OfficialsRepository;
use App\Repository\RegistrationsRepository;
use App\Repository\TransportRepository;
use Doctrine\ORM\NonUniqueResultException;
use Exception;
use Swift_Mailer;
use Swift_Message;
use function array_filter;
use function array_map;
use function count;
use function date_format;
use function file_get_contents;
use function implode;
use function in_array;
use function json_decode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use function json_encode;

class LoginController extends AbstractController
{
    /**
     * @Route("/login", name="login")
     * @param AuthenticationUtils $authenticationUtils
     * @param $locales
     * @param $defaultLocale
     * @return Response
     */
    public function login(AuthenticationUtils $authenticationUtils, $locales, $defaultLocale): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // locales and defaultLocales must be used at least once in code. This block is to avoid them being maked as unused.
        if ($locales === $defaultLocale && $locales !== $defaultLocale) {
            throw $this->createNotFoundException(
                'This should never happen.'
            );
        }

        $login_form = $this->createForm(LoginType::class);

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'my_form' => $login_form->createView(),
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }


    /**
     * @Route("/forgot_password", name="forgot_password")
     * @param Request $request
     * @param RegistrationsRepository $registrationsRepository
     * @param Swift_Mailer $mailer
     * @return Response
     * @throws NonUniqueResultException
     */
    public function forgotPassword(Request $request, RegistrationsRepository $registrationsRepository, Swift_Mailer $mailer): Response
    {
        $form = $this->createForm(ForgotPasswordType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $email = $request->get('forgot_password')['email'];
            $registration = $registrationsRepository->findOneByEmail($email);

            if ($registration) {

                $hash = hash('sha256', $registration->getPassword(), false);
                $uid = $registration->getId();
                $root = 'https://www.anmeldung.thueringer-judoverband.de';
                $locale = $request->getLocale();

                $link = $root . '/' . $locale . '/reset_password/' . $uid . '/' . $hash;

                $message = (new Swift_Message('Forgot your password'))
                    ->setFrom('anmeldung@thueringer-judoverband.de')
                    ->setTo($registration->getEmail())
                    ->setBody($this->renderView('emails/forgot_password.html.twig', [
                        'registration' => $registration,
                        'link' => $link
                    ]), 'text/html');

                $mailer->send($message);
            }
            return $this->redirectToRoute('login');
        }

        return $this->render('security/forgot_password.html.twig', [
            'my_form' => $form->createView()
        ]);
    }

    /**
     * @Route("/admin", name="admin")
     * @param Request $request
     * @param RegistrationsRepository $registrationsRepository
     * @param OfficialsRepository $officialsRepository
     * @param ContestantsRepository $contestantsRepository
     * @param TransportRepository $transportRepository
     * @param ChangeSetRepository $changeSetRepository
     * @param Swift_Mailer $mailer
     * @param KernelInterface $appKernel
     * @return Response
     * @throws Exception
     */
    public function admin(Request $request, RegistrationsRepository $registrationsRepository, OfficialsRepository $officialsRepository, ContestantsRepository $contestantsRepository, TransportRepository $transportRepository, ChangeSetRepository $changeSetRepository, Swift_Mailer $mailer, KernelInterface $appKernel): Response
    {
        $showSentNotification = false;

        $form = $this->createForm(ChangeSetType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $after = $form->getData()['from_date'];
            $before = $form->getData()['to_date'];

            $newRegistrations = $registrationsRepository->findByDate($after, $before);
            $newOfficials = $officialsRepository->findByDate($after, $before);
            $newContestants = $contestantsRepository->findByDate($after, $before);
            $newTransports = $transportRepository->findByDate($after, $before);
            $changes = $changeSetRepository->findByDate($after, $before);

            /*
             * filter changes (only want drops of each repository)
             */
            $registrationsDrops = array_filter($changes, static function (ChangeSet $changeSet) {
                return $changeSet->getType() === 'DROP' && $changeSet->getName() === 'registration';
            });
            $officialsDrops = array_filter($changes, static function (ChangeSet $changeSet) {
                return $changeSet->getType() === 'DROP' && $changeSet->getName() === 'official';
            });
            $contestantsDrops = array_filter($changes, static function (ChangeSet $changeSet) {
                return $changeSet->getType() === 'DROP' && $changeSet->getName() === 'contestant';
            });
            $transportsDrops = array_filter($changes, static function (ChangeSet $changeSet) {
                return $changeSet->getType() === 'DROP' && $changeSet->getName() === 'transport';
            });

            $getId = static function ($object) {
                /** @noinspection PhpUndefinedMethodInspection */
                return $object->getId();
            };

            $getNameId = static function (ChangeSet $object) {
                return $object->getNameId();
            };

            /*
             * filter changes (only want those who have not been dropped or just been added)
             */
            $registrationsChanges = array_filter($changes, static function (ChangeSet $changeSet) use ($getId, $getNameId, $newRegistrations, $registrationsDrops) {
                return $changeSet->getType() === 'UPDATE'
                    && $changeSet->getName() === 'registration'
                    && !in_array($changeSet->getNameId(), array_map($getId, $newRegistrations), true)
                    && !in_array($changeSet->getNameId(), array_map($getNameId, $registrationsDrops), true);
            });
            $officialsChanges = array_filter($changes, static function (ChangeSet $changeSet) use ($getId, $getNameId, $newOfficials, $officialsDrops) {
                return $changeSet->getType() === 'UPDATE'
                    && $changeSet->getName() === 'official'
                    && !in_array($changeSet->getNameId(), array_map($getId, $newOfficials), true)
                    && !in_array($changeSet->getNameId(), array_map($getNameId, $officialsDrops), true);
            });
            $contestantsChanges = array_filter($changes, static function (ChangeSet $changeSet) use ($getId, $getNameId, $newContestants, $contestantsDrops) {
                return $changeSet->getType() === 'UPDATE'
                    && $changeSet->getName() === 'contestant'
                    && !in_array($changeSet->getNameId(), array_map($getId, $newContestants), true)
                    && !in_array($changeSet->getNameId(), array_map($getNameId, $contestantsDrops), true);
            });
            $transportsChanges = array_filter($changes, static function (ChangeSet $changeSet) use ($getId, $getNameId, $newTransports, $transportsDrops) {
                return $changeSet->getType() === 'UPDATE'
                    && $changeSet->getName() === 'transport'
                    && !in_array($changeSet->getNameId(), array_map($getId, $newTransports), true)
                    && !in_array($changeSet->getNameId(), array_map($getNameId, $transportsDrops), true);
            });

            $setClubRegistration = static function (ChangeSet $changeSet) use ($registrationsRepository) {
                $registration = $registrationsRepository->findOneById($changeSet->getNameId());
                $changeSet->setChangeSetObject(json_decode($changeSet->getChangeSet(), false));
                $changeSet->setObject($registration);
                return $changeSet;
            };
            $setClubOfficials = static function (ChangeSet $changeSet) use ($officialsRepository) {
                $official = $officialsRepository->findOneById($changeSet->getNameId());
                $changeSet->setChangeSetObject(json_decode($changeSet->getChangeSet(), false));
                $changeSet->setObject($official);
                return $changeSet;
            };
            $setClubContestants = static function (ChangeSet $changeSet) use ($contestantsRepository) {
                $contestant = $contestantsRepository->findOneById($changeSet->getNameId());
                $changeSet->setChangeSetObject(json_decode($changeSet->getChangeSet(), false));
                $changeSet->setObject($contestant);
                return $changeSet;
            };
            $setClubTransport = static function (ChangeSet $changeSet) use ($transportRepository) {
                $transport = $transportRepository->findOneById($changeSet->getNameId());
                $changeSet->setChangeSetObject(json_decode($changeSet->getChangeSet(), false));
                $changeSet->setObject($transport);
                return $changeSet;
            };

            $registrationsChanges = array_map($setClubRegistration, $registrationsChanges);
            $officialsChanges = array_map($setClubOfficials, $officialsChanges);
            $contestantsChanges = array_map($setClubContestants, $contestantsChanges);
            $transportsChanges = array_map($setClubTransport, $transportsChanges);

            /*
             * retrieve object from change set (and adjust timestamp)
             */
            $getObject = function (ChangeSet $changeSet) {
                $obj = (object)json_decode($changeSet->getChangeSet());
                $obj->timestamp = $changeSet->getTimestamp();
                return $obj;
            };
            $officialsDrops = array_map($getObject, $officialsDrops);
            $contestantsDrops = array_map($getObject, $contestantsDrops);
            $transportsDrops = array_map($getObject, $transportsDrops);

            $message = (new Swift_Message('Change history from ' . date_format($after, 'Y-m-d H:i:s') . ' to ' . date_format($before, 'Y-m-d H:i:s')))
                ->setFrom('anmeldung@thueringer-judoverband.de')
                ->setTo($this->getUser()->getEmail())
                ->setBody($this->renderView('emails/change_history.html.twig', [
                    'from_date' => date_format($after, 'Y-m-d H:i:s'),
                    'to_date' => date_format($before, 'Y-m-d H:i:s'),
                    'newRegistrations' => $newRegistrations,
                    'newOfficials' => $newOfficials,
                    'newContestants' => $newContestants,
                    'newTransports' => $newTransports,
                    'droppedOfficials' => $officialsDrops,
                    'droppedContestants' => $contestantsDrops,
                    'droppedTransports' => $transportsDrops,
                    'registrationsChanges' => $registrationsChanges,
                    'officialsChanges' => $officialsChanges,
                    'contestantsChanges' => $contestantsChanges,
                    'transportsChanges' => $transportsChanges,
                    'changes' => $changes,
                ]), 'text/html');

            $showSentNotification = $mailer->send($message);

            // unused code
            /*

            usort($newOfficials, function (Official $a, Official $b) {
                if (null === $a->getRegistration() && null === $b->getRegistration()) {
                    return $a->getRegistration()->getId() <=> $b->getRegistration()->getId();
                }
                return 0;
            });
            usort($newContestants, function (Contestant $a, Contestant $b) {
                if (null === $a->getRegistration() && null === $b->getRegistration()) {
                    return $a->getRegistration()->getId() <=> $b->getRegistration()->getId();
                }
                return 0;
            });
            usort($newTransports, function (Transport $a, Transport $b) {
                if (null === $a->getRegistration() && null === $b->getRegistration()) {
                    return $a->getRegistration()->getId() <=> $b->getRegistration()->getId();
                }
                return 0;
            });

            // remove new officials that are already part of new registrations
            $newOfficials = array_diff($newOfficials, array_map(function (Registration $o){
                return $o->getOfficials();
            }, $newRegistrations));

            // remove new contestnats that are already part of new registrations
            $newContestants = array_diff($newContestants, array_map(function (Registration $o){
                return $o->getContestants();
            }, $newRegistrations));

            // remove new transports that are already part of new registrations
            $newTransport = array_diff($newTransport, array_map(function (Registration $o){
                return $o->getTransports();
            }, $newRegistrations));
            //*/
        }

        $registrations = array_filter($registrationsRepository->findAll(), function (Registration $registration) {
	        return !in_array($registration->getId(), [-1, -2, -3, -5], true);
        });

        $countRegistrations = count($registrations);
        $countOfficials = $officialsRepository->count([]);
        $countContestants = $contestantsRepository->count([]);
        $countFri = $officialsRepository->count(['friday' => true]) + $contestantsRepository->count(['friday' => true]);
        $countSat = $officialsRepository->count(['saturday' => true]) + $contestantsRepository->count(['saturday' => true]);
        $countITCtillTu = $officialsRepository->count(['itc' => 'su-tu']) + $contestantsRepository->count(['itc' => 'su-tu']);
        $countITCtillWe = $officialsRepository->count(['itc' => 'su-we']) + $contestantsRepository->count(['itc' => 'su-we']);
        $countArrivals = $transportRepository->count(['isArrival' => true]);
        $countDepartures = $transportRepository->count(['isArrival' => false]);

        return $this->render('security/admin.html.twig',
            [
                'registrations' => $registrations,
                'showSentNotification' => $showSentNotification,
                'allOfficialsJSON' => json_encode($officialsRepository->findAll()),
                'allOfficials' => $this->officialsToCVS($officialsRepository->findAll(), $appKernel),
                'allContestantsJSON' => json_encode($contestantsRepository->findAll()),
                'allContestants' => $this->contestantsToCVS($contestantsRepository->findAll(), $appKernel),
                'countRegistrations' => $countRegistrations,
                'countOfficials' => $countOfficials,
                'countContestants' => $countContestants,
                'countFri' => $countFri,
                'countSat' => $countSat,
                'countITCtillTu' => $countITCtillTu,
                'countITCtillWe' => $countITCtillWe,
                'countArrivals' => $countArrivals,
                'countDepartures' => $countDepartures,
                'form' => $form->createView(),
            ]);
    }


    private function contestantsToCVS(array $contestants, KernelInterface $kernel): string
    {
        $contestants = array_filter($contestants, static function (Contestant $contestant) {
            return $contestant->getRegistration()->getId() > 0;
        });

        $path = $kernel->getProjectDir();
        $codes = json_decode(file_get_contents($path . '/public/data/ISO3166-1-Alpha-2_to_IOC.json'), true);
        return implode("\n", array_map(static function (Contestant $contestant) use ($codes) {
            return self::contestantToCVS($contestant, $codes);
        }, $contestants));
    }

    private static function contestantToCVS(Contestant $contestant, $codes): string
    {
        $data = array(
            $contestant->getRegistration()->getId(),
            $codes ? $codes[$contestant->getRegistration()->getCountry()] : $contestant->getRegistration()->getCountry(),
            $contestant->getRegistration()->getClub(),
            $contestant->getLastName(),
            $contestant->getFirstName(),
            $contestant->getYear(),
            $contestant->getWeightCategory(),
            $contestant->getAgeCategory(),
            '',
            $contestant->getItc(),
            '',
            '"'. preg_replace(['/\r/','/\n/'], '', $contestant->getComment()) . '"',
        );

        return implode(',', $data);
    }


    private
    function officialsToCVS(array $officials, KernelInterface $kernel): string
    {
        $officials = array_filter($officials, static function (Official $official) {
            return $official->getRegistration()->getId() > 0;
        });
        $path = $kernel->getProjectDir();
        $codes = json_decode(file_get_contents($path . '/public/data/ISO3166-1-Alpha-2_to_IOC.json'), true);
        return implode("\n", array_map(static function (Official $official) use ($codes) {
            return self::officialToCVS($official, $codes);
        }, $officials));
    }

    private static function officialToCVS(Official $official, $codes = null): string
    {
        $data = [
            $official->getRegistration()->getId(),
            $codes ? $codes[$official->getRegistration()->getCountry()] : $official->getRegistration()->getCountry(),
            $official->getRegistration()->getClub(),
            $official->getLastName(),
            $official->getFirstName(),
            $official->getRole(),
            '', '',
            $official->getGender(),
            $official->getItc(),
            '',
            '"' . preg_replace(['/\r/','/\n/'], '', $official->getComment()) . '"',
        ];

        return implode(',', $data);
    }

}
