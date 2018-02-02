<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Card;
use AppBundle\Entity\Deckslot;
use AppBundle\Entity\Tournament;
use AppBundle\Service\ActivityHelper;
use AppBundle\Service\DecklistManager;
use AppBundle\Service\Judge;
use AppBundle\Service\ModerationHelper;
use AppBundle\Service\RotationService;
use AppBundle\Service\TextProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Entity\Deck;
use AppBundle\Entity\Decklist;
use AppBundle\Entity\Decklistslot;
use AppBundle\Entity\Comment;
use AppBundle\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use AppBundle\Entity\Legality;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SocialController extends Controller
{

    /**
     * @param int                    $deck_id
     * @param EntityManagerInterface $entityManager
     * @param Judge                  $judge
     * @return JsonResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function publishAction(int $deck_id, EntityManagerInterface $entityManager, Judge $judge)
    {
        $response = new JsonResponse();

        /** @var Deck $deck */
        $deck = $entityManager->getRepository('AppBundle:Deck')->find($deck_id);

        if ($this->getUser()->getId() != $deck->getUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        $lastPack = $deck->getLastPack();
        if (!$lastPack->getDateRelease() || $lastPack->getDateRelease() > new \DateTime()) {
            $response->setData([
                'allowed' => false,
                'message' => "You cannot publish this deck yet, because it has unreleased cards.",
            ]);

            return $response;
        }
        $analyse = $judge->analyse($deck->getSlots()->toArray());

        if (is_string($analyse)) {
            $response->setData([
                'allowed' => false,
                'message' => $judge->problem($analyse),
            ]);

            return $response;
        }

        $new_content = json_encode($deck->getContent());
        $new_signature = md5($new_content);
        $old_decklists = $entityManager
                              ->getRepository('AppBundle:Decklist')
                              ->findBy([
                                  'signature' => $new_signature,
                              ]);
        foreach ($old_decklists as $decklist) {
            if (json_encode($decklist->getContent()) == $new_content) {
                $url = $this->generateUrl('decklist_detail', [
                    'decklist_id'   => $decklist->getId(),
                    'decklist_name' => $decklist->getPrettyName(),
                ]);
                $response->setData([
                    'allowed' => true,
                    'message' => 'This deck is <a href="' . $url . '">already published</a>. Are you sure you want to publish a duplicate?',
                ]);

                return $response;
            }
        }

        $response->setData([
            'allowed' => true,
            'message' => '',
        ]);

        return $response;
    }

    /**
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @param DecklistManager        $decklistManager
     * @param Judge                  $judge
     * @param TextProcessor          $textProcessor
     * @param RotationService        $rotationService
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function newAction(Request $request, EntityManagerInterface $entityManager, DecklistManager $decklistManager, Judge $judge, TextProcessor $textProcessor, RotationService $rotationService)
    {
        $deck_id = filter_var($request->request->get('deck_id'), FILTER_SANITIZE_NUMBER_INT);
        /** @var Deck $deck */
        $deck = $entityManager->getRepository('AppBundle:Deck')->find($deck_id);
        if ($this->getUser()->getId() != $deck->getUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        $lastPack = $deck->getLastPack();
        if (!$lastPack->getDateRelease() || $lastPack->getDateRelease() > new \DateTime()) {
            throw $this->createAccessDeniedException();
        }

        $analyse = $judge->analyse($deck->getSlots()->toArray());
        if (is_string($analyse)) {
            throw $this->createAccessDeniedException();
        }

        $new_content = json_encode($deck->getContent());
        $new_signature = md5($new_content);

        $name = substr(filter_var($request->request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES), 0, 60);
        if (empty($name)) {
            $name = "Untitled";
        }

        $rawdescription = \trim($request->request->get('description'));
        $description = $textProcessor->markdown($rawdescription);

        $tournament_id = filter_var($request->request->get('tournament'), FILTER_SANITIZE_NUMBER_INT);
        /** @var Tournament|null $tournament */
        $tournament = $entityManager->getRepository('AppBundle:Tournament')->find($tournament_id);

        $decklist = new Decklist();
        $decklist->setName($name);
        $decklist->setPrettyname(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)));
        $decklist->setRawdescription($rawdescription);
        $decklist->setDescription($description);
        $decklist->setUser($this->getUser());
        $decklist->setSignature($new_signature);
        $decklist->setIdentity($deck->getIdentity());
        $decklist->setFaction($deck->getIdentity()
                                   ->getFaction());
        $decklist->setSide($deck->getSide());
        $decklist->setLastPack($deck->getLastPack());
        $decklist->setNbvotes(0);
        $decklist->setNbfavorites(0);
        $decklist->setNbcomments(0);
        $decklist->setDotw(0);
        $decklist->setModerationStatus(Decklist::MODERATION_PUBLISHED);
        $decklist->setTournament($tournament);
        $decklist->setIsLegal(true);
        $decklist->setMwl($deck->getMwl());
        foreach ($deck->getSlots() as $slot) {
            $card = $slot->getCard();
            $decklistslot = new Decklistslot();
            $decklistslot->setQuantity($slot->getQuantity());
            $decklistslot->setCard($card);
            $decklistslot->setDecklist($decklist);
            $decklist->getSlots()->add($decklistslot);
        }
        if (count($deck->getChildren())) {
            $decklist->setPrecedent($deck->getChildren()[0]);
        } elseif ($deck->getParent()) {
            $decklist->setPrecedent($deck->getParent());
        }
        $decklist->setParent($deck);
        $decklist->setRotation($rotationService->findCompatibleRotation($decklist));

        $entityManager->persist($decklist);

        $mwls = $entityManager->getRepository('AppBundle:Mwl')->findAll();
        foreach ($mwls as $mwl) {
            $legality = new Legality();
            $legality->setDecklist($decklist);
            $legality->setMwl($mwl);
            $judge->computeLegality($legality);
            $entityManager->persist($legality);
        }

        $entityManager->flush();
        $decklist->setIsLegal($decklistManager->isDecklistLegal($decklist));
        $entityManager->flush();

        return $this->redirect(
            $this->generateUrl(
                'decklist_detail',
                [
                    'decklist_id'   => $decklist->getId(),
                    'decklist_name' => $decklist->getPrettyname(),
                ]
            )
        );
    }

    /**
     * @param int                    $decklist_id
     * @param EntityManagerInterface $entityManager
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     */
    public function viewAction(int $decklist_id, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('short_cache'));

        $dbh = $entityManager->getConnection();
        $rows = $dbh->executeQuery(
            "SELECT
				d.id,
				d.date_update,
				d.name,
				d.prettyname,
				d.date_creation,
				d.rawdescription,
				d.description,
                		d.signature,
				d.precedent_decklist_id precedent,
                                d.tournament_id,
                                t.description tournament,
				u.id user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				u.donation,
				c.code identity_code,
				f.code faction_code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments,
                                d.moderation_status,
                                d.is_legal,
                                d.rotation_id
				FROM decklist d
				JOIN user u ON d.user_id=u.id
				JOIN card c ON d.identity_id=c.id
				JOIN faction f ON d.faction_id=f.id
                                LEFT JOIN tournament t ON d.tournament_id=t.id
				WHERE d.id=?
                                AND d.moderation_status IN (0,1,2)
				",
            [
                $decklist_id,
            ]
        )->fetchAll();

        if (empty($rows)) {
            throw $this->createNotFoundException();
        }

        $decklist = $rows[0];

        $comments = $dbh->executeQuery(
            "SELECT
				c.id,
				c.date_creation,
				c.user_id,
				u.username author,
				u.faction authorcolor,
                u.donation,
				c.text,
                c.hidden
				FROM comment c
				JOIN user u ON c.user_id=u.id
				WHERE c.decklist_id=?
				ORDER BY date_creation ASC",
            [
                $decklist_id,
            ]
        )->fetchAll();

        $commenters = array_values(array_unique(array_merge([$decklist['username']], array_map(function ($item) {
            return $item['author'];
        }, $comments))));

        $cards = $dbh->executeQuery("SELECT
				c.code card_code,
				s.quantity qty
				FROM decklistslot s
				JOIN card c ON s.card_id=c.id
				WHERE s.decklist_id=?
				ORDER BY c.code ASC", [
            $decklist_id,
        ])->fetchAll();


        $decklist['comments'] = $comments;
        $decklist['cards'] = $cards;

        $precedent_decklists = $dbh->executeQuery(
            "SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					FROM decklist d
					WHERE d.id=?
                                        AND d.moderation_status IN (0,1)
					ORDER BY d.date_creation ASC",
            [
                $decklist['precedent'],
            ]
        )->fetchAll();

        $successor_decklists = $dbh->executeQuery(
            "SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					FROM decklist d
					WHERE d.precedent_decklist_id=?
                                        AND d.moderation_status IN (0,1)
					ORDER BY d.date_creation ASC",
            [
                $decklist_id,
            ]
        )->fetchAll();

        $duplicate = $dbh->executeQuery(
            "SELECT
					d.id,
					d.name,
					d.prettyname
					FROM decklist d
					WHERE d.signature=?
					AND d.date_creation<?
                                        AND d.moderation_status IN (0,1)
					ORDER BY d.date_creation ASC
					LIMIT 0,1",
            [
                $decklist['signature'],
                $decklist['date_creation'],
            ]
        )->fetch();

        $tournaments = $dbh->executeQuery(
            "SELECT
					t.id,
					t.description
                FROM tournament t
                ORDER BY t.description DESC"
        )->fetchAll();

        $legalities = $dbh->executeQuery(
            "SELECT
        			m.code,
        			m.name,
        			l.is_legal
        		FROM legality l
        		LEFT JOIN mwl m ON l.mwl_id=m.id
        		WHERE l.decklist_id=?
        		ORDER BY m.date_start DESC",
            [$decklist_id]
        )->fetchAll();

        $mwl = $dbh->executeQuery(
            "SELECT
                m.code
            FROM mwl m
            WHERE m.active=1"
        )->fetch();
        if ($mwl) {
            $mwl = $mwl['code'];
        }

        $rotation = $decklist['rotation_id']
            ? $dbh->executeQuery("SELECT r.name FROM rotation r WHERE r.id=?", [$decklist['rotation_id']])->fetch()['name']
            : null;

        $claims = $dbh->executeQuery("SELECT "
            . "c.url, "
            . "c.rank, "
            . "c.name, "
            . "c.participants, "
            . "u.id user_id, "
            . "u.username "
            . "FROM Claim c "
            . "JOIN decklist d ON d.id=c.decklist_id "
            . "JOIN user u ON u.id=c.user_id "
            . "WHERE d.id=?", [$decklist_id])->fetchAll();

        $packs = $dbh->executeQuery("SELECT DISTINCT
				p.code code,
				p.name name,
				p.position pack_position,
				y.code cycle_code,
				y.name cycle_name,
				y.position cycle_position
				FROM pack p
				JOIN cycle y ON p.cycle_id=y.id
        		JOIN card c ON c.pack_id=p.id
        		JOIN decklistslot s ON s.card_id=c.id
				WHERE s.decklist_id=?
				ORDER BY y.position ASC, p.position ASC", [$decklist_id])->fetchAll();

        return $this->render('/Decklist/decklist.html.twig', [
            'pagetitle'           => $decklist['name'],
            'decklist'            => $decklist,
            'commenters'          => $commenters,
            'precedent_decklists' => $precedent_decklists,
            'successor_decklists' => $successor_decklists,
            'duplicate'           => $duplicate,
            'tournaments'         => $tournaments,
            'legalities'          => $legalities,
            'claims'              => $claims,
            'mwl'                 => $mwl,
            'rotation'            => $rotation,
            'packs'               => $packs,
        ], $response);
    }

    /**
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function favoriteAction(Request $request, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException();
        }

        $author = $decklist->getUser();

        $dbh = $entityManager->getConnection();
        $is_favorite = $dbh->executeQuery("SELECT
				count(*)
				FROM decklist d
				JOIN favorite f ON f.decklist_id=d.id
				WHERE f.user_id=?
				AND d.id=?", [
            $user->getId(),
            $decklist_id,
        ])
                           ->fetch(\PDO::FETCH_NUM)[0];

        if ($is_favorite) {
            $decklist->setNbfavorites($decklist->getNbfavorites() - 1);
            $user->removeFavorite($decklist);
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() - 5);
            }
        } else {
            $decklist->setNbfavorites($decklist->getNbfavorites() + 1);
            $user->addFavorite($decklist);
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() + 5);
            }
        }
        $entityManager->flush();

        return new Response(count($decklist->getFavorites()));
    }

    /**
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @param \Swift_Mailer          $mailer
     * @param TextProcessor          $textProcessor
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function commentAction(Request $request, EntityManagerInterface $entityManager, \Swift_Mailer $mailer, TextProcessor $textProcessor)
    {
        $user = $this->getUser();

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        $decklist = $entityManager
                         ->getRepository('AppBundle:Decklist')
                         ->find($decklist_id);
        if (!$decklist instanceof Decklist) {
            throw $this->createNotFoundException();
        }

        $comment_text = trim($request->get('comment'));
        if (!empty($comment_text)) {
            $comment_text = preg_replace(
                '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))(?::\d+)?)(?:[^\s]*)?%iu',
                '[$1]($0)',
                $comment_text
            );

            $mentionned_usernames = [];
            $matches = [];
            if (preg_match_all('/`@([\w_]+)`/', $comment_text, $matches, PREG_PATTERN_ORDER)) {
                $mentionned_usernames = array_unique($matches[1]);
            }

            $comment_html = $textProcessor->markdown($comment_text);

            $comment = new Comment();
            $comment->setText($comment_html);
            $comment->setAuthor($user);
            $comment->setDecklist($decklist);
            $comment->setHidden($user->getSoftBan());

            $entityManager->persist($comment);

            $decklist->setNbcomments($decklist->getNbcomments() + 1);

            $entityManager->flush();

            // send emails
            $spool = [];
            if ($decklist->getUser()->getNotifAuthor()) {
                if (!isset($spool[$decklist->getUser()->getEmail()])) {
                    $spool[$decklist->getUser()->getEmail()] = '/Emails/newcomment_author.html.twig';
                }
            }
            foreach ($decklist->getComments() as $comment) {
                /** @var Comment $comment */
                $commenter = $comment->getAuthor();
                if ($commenter && $commenter->getNotifCommenter()) {
                    if (!isset($spool[$commenter->getEmail()])) {
                        $spool[$commenter->getEmail()] = '/Emails/newcomment_commenter.html.twig';
                    }
                }
            }
            foreach ($mentionned_usernames as $mentionned_username) {
                /** @var User $mentionned_user */
                $mentionned_user = $entityManager->getRepository('AppBundle:User')->findOneBy(['username' => $mentionned_username]);
                if ($mentionned_user && $mentionned_user->getNotifMention()) {
                    if (!isset($spool[$mentionned_user->getEmail()])) {
                        $spool[$mentionned_user->getEmail()] = '/Emails/newcomment_mentionned.html.twig';
                    }
                }
            }
            unset($spool[$user->getEmail()]);

            $email_data = [
                'username'      => $user->getUsername(),
                'decklist_name' => $decklist->getName(),
                'url'           => $this->generateUrl('decklist_detail', ['decklist_id' => $decklist->getId(), 'decklist_name' => $decklist->getPrettyname()], UrlGeneratorInterface::ABSOLUTE_URL) . '#' . $comment->getId(),
                'comment'       => $comment_html,
                'profile'       => $this->generateUrl('user_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
            foreach ($spool as $email => $view) {
                $message = \Swift_Message::newInstance()
                                         ->setSubject("[NetrunnerDB] New comment")
                                         ->setFrom(["alsciende@netrunnerdb.com" => $user->getUsername()])
                                         ->setTo($email)
                                         ->setBody($this->renderView($view, $email_data), 'text/html');
                $mailer->send($message);
            }
        }

        return $this->redirect($this->generateUrl('decklist_detail', [
            'decklist_id'   => $decklist_id,
            'decklist_name' => $decklist->getPrettyname(),
        ]));
    }

    /**
     * @param int                    $comment_id
     * @param int                    $hidden
     * @param EntityManagerInterface $entityManager
     * @return JsonResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function hidecommentAction(int $comment_id, int $hidden, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();

        $comment = $entityManager->getRepository('AppBundle:Comment')->find($comment_id);
        if (!$comment instanceof Comment) {
            throw $this->createNotFoundException();
        }

        if ($comment->getDecklist()->getUser()->getId() !== $user->getId()) {
            return new JsonResponse("You don't have permission to edit this comment.");
        }

        $comment->setHidden((boolean) $hidden);
        $entityManager->flush();

        return new JsonResponse(true);
    }

    /**
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function voteAction(Request $request, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);

        if ($decklist->getUser()->getId() != $user->getId()) {
            $query = $entityManager
                        ->createQueryBuilder()
                        ->select('d')
                        ->from(Decklist::class, 'd')
                        ->innerJoin('d.votes', 'u')
                        ->where('d.id = :decklist_id')
                        ->andWhere('u.id = :user_id')
                        ->setParameter('decklist_id', $decklist_id)
                        ->setParameter('user_id', $user->getId())
                        ->getQuery();

            $result = $query->getResult();
            if (empty($result)) {
                $user->addVote($decklist);
                $author = $decklist->getUser();
                $author->setReputation($author->getReputation() + 1);
                $decklist->setNbvotes($decklist->getNbvotes() + 1);
                $entityManager->flush();
            }
        }

        return new Response(count($decklist->getVotes()));
    }

    /**
     * @param int                    $decklist_id
     * @param EntityManagerInterface $entityManager
     * @param Judge                  $judge
     * @return Response
     */
    public function textexportAction(int $decklist_id, EntityManagerInterface $entityManager, Judge $judge)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('long_cache'));

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException();
        }

        $classement = $judge->classe($decklist->getSlots()->toArray(), $decklist->getIdentity());

        $lines = [];
        $types = [
            "Event",
            "Hardware",
            "Resource",
            "Icebreaker",
            "Program",
            "Agenda",
            "Asset",
            "Upgrade",
            "Operation",
            "Barrier",
            "Code Gate",
            "Sentry",
            "ICE",
        ];

        $lines[] = sprintf(
            "%s (%s)",
            $decklist->getIdentity()->getTitle(),
            $decklist->getIdentity()->getPack()->getName()
        );

        foreach ($types as $type) {
            if (isset($classement[$type]) && $classement[$type]['qty']) {
                $lines[] = "";
                $lines[] = $type . " (" . $classement[$type]['qty'] . ")";
                foreach ($classement[$type]['slots'] as $slot) {
                    $inf = "";
                    for ($i = 0; $i < $slot['influence']; $i++) {
                        if ($i % 5 == 0) {
                            $inf .= " ";
                        }
                        $inf .= "•";
                    }
                    /** @var Card $card */
                    $card = $slot['card'];
                    $lines[] = $slot['qty'] . "x " . $card->getTitle() . " (" . $card->getPack()->getName() . ") " . $inf;
                }
            }
        }
        $content = implode("\r\n", $lines);

        $name = mb_strtolower($decklist->getName());
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
        $name = preg_replace('/--+/', '-', $name);

        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('Content-Disposition', 'attachment;filename=' . $name . ".txt");

        $response->setContent($content);

        return $response;
    }

    /**
     * @param int                    $decklist_id
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function octgnexportAction(int $decklist_id, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('long_cache'));

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException();
        }

        $rd = [];
        $identity = null;
        /** @var Deckslot $slot */
        foreach ($decklist->getSlots() as $slot) {
            if ($slot->getCard()
                     ->getType()
                     ->getName() == "Identity") {
                $identity = [
                    "index" => $slot->getCard()->getCode(),
                    "name"  => $slot->getCard()->getTitle(),
                ];
            } else {
                $rd[] = [
                    "index" => $slot->getCard()->getCode(),
                    "name"  => $slot->getCard()->getTitle(),
                    "qty"   => $slot->getQuantity(),
                ];
            }
        }
        $name = mb_strtolower($decklist->getName());
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
        $name = preg_replace('/--+/', '-', $name);
        if (empty($identity)) {
            return new Response('no identity found');
        }

        return $this->octgnexport("$name.o8d", $identity, $rd, $decklist->getRawdescription(), $response);
    }

    /**
     * @param string   $filename
     * @param string   $identity
     * @param array    $rd
     * @param string   $description
     * @param Response $response
     * @return Response
     */
    public function octgnexport(string $filename, string $identity, array $rd, string $description, Response $response)
    {
        $content = $this->renderView('/octgn.xml.twig', [
            "identity"    => $identity,
            "rd"          => $rd,
            "description" => strip_tags($description),
        ]);

        $response->headers->set('Content-Type', 'application/octgn');
        $response->headers->set('Content-Disposition', 'attachment;filename=' . $filename);

        $response->setContent($content);

        return $response;
    }

    /**
     * @param int                    $decklist_id
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @param TextProcessor          $textProcessor
     * @param ModerationHelper       $moderationHelper
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function editAction(int $decklist_id, Request $request, EntityManagerInterface $entityManager, TextProcessor $textProcessor, ModerationHelper $moderationHelper)
    {
        $user = $this->getUser();

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist || $decklist->getUser()->getId() != $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $name = trim(filter_var($request->request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
        $name = substr($name, 0, 60);
        if (empty($name)) {
            $name = "Untitled";
        }
        $rawdescription = trim($request->request->get('description'));
        $description = $textProcessor->markdown($rawdescription);

        $tournament_id = filter_var($request->request->get('tournament'), FILTER_SANITIZE_NUMBER_INT);
        /** @var Tournament|null $tournament */
        $tournament = $entityManager->getRepository('AppBundle:Tournament')->find($tournament_id);

        $derived_from = $request->request->get('derived');
        $matches = [];
        if (preg_match('/^(\d+)$/', $derived_from, $matches)) {
        } elseif (preg_match('/decklist\/(\d+)\//', $derived_from, $matches)) {
            $derived_from = $matches[1];
        } else {
            $derived_from = null;
        }

        if (!$derived_from) {
            $precedent_decklist = null;
        } else {
            /** @var Decklist $precedent_decklist */
            $precedent_decklist = $entityManager->getRepository('AppBundle:Decklist')->find($derived_from);
            if (!$precedent_decklist || $precedent_decklist->getDateCreation() > $decklist->getDateCreation()) {
                $precedent_decklist = $decklist->getPrecedent();
            }
        }

        $decklist->setName($name);
        $decklist->setPrettyname(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)));
        $decklist->setRawdescription($rawdescription);
        $decklist->setDescription($description);
        $decklist->setPrecedent($precedent_decklist);
        $decklist->setTournament($tournament);

        if ($decklist->getModerationStatus() === Decklist::MODERATION_TRASHED) {
            $moderationHelper->changeStatus($this->getUser(), $decklist, Decklist::MODERATION_RESTORED);
        }

        $entityManager->flush();

        return $this->redirect($this->generateUrl('decklist_detail', [
            'decklist_id'   => $decklist_id,
            'decklist_name' => $decklist->getPrettyname(),
        ]));
    }

    /**
     * @param int                    $decklist_id
     * @param EntityManagerInterface $entityManager
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function deleteAction(int $decklist_id, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist || $decklist->getUser()->getId() != $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        if ($decklist->getNbvotes() || $decklist->getNbfavorites() || $decklist->getNbcomments()) {
            throw $this->createAccessDeniedException();
        }

        $precedent = $decklist->getPrecedent();

        $children_decks = $decklist->getChildren();
        /** @var Deck $children_deck */
        foreach ($children_decks as $children_deck) {
            $children_deck->setParent($precedent);
        }

        $successor_decklists = $decklist->getSuccessors();
        /** @var Decklist $successor_decklist */
        foreach ($successor_decklists as $successor_decklist) {
            $successor_decklist->setPrecedent($precedent);
        }

        $entityManager->remove($decklist);
        $entityManager->flush();

        return $this->redirect($this->generateUrl('decklists_list', [
            'type' => 'mine',
        ]));
    }

    /**
     * @param int                    $user_id
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function profileAction(int $user_id, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('short_cache'));

        /** @var User $user */
        $user = $entityManager->getRepository('AppBundle:User')->find($user_id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $decklists = $entityManager->getRepository('AppBundle:Decklist')->findBy(['user' => $user]);
        $nbdecklists = count($decklists);

        $reviews = $entityManager->getRepository('AppBundle:Review')->findBy(['user' => $user]);
        $nbreviews = count($reviews);


        return $this->render('/Default/public_profile.html.twig', [
            'pagetitle'   => $user->getUsername(),
            'user'        => $user,
            'nbdecklists' => $nbdecklists,
            'nbreviews'   => $nbreviews,
        ], $response);
    }

    /**
     * @param int                    $user_id
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function followAction(int $user_id, Request $request, EntityManagerInterface $entityManager)
    {
        /* who is following */
        $follower = $this->getUser();
        /* who is followed */
        $following = $entityManager->getRepository('AppBundle:User')->find($user_id);

        if (!$following instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $found = false;
        foreach ($follower->getFollowing() as $user) {
            if ($user->getId() === $following->getId()) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $follower->addFollowing($following);
            $entityManager->flush();
        }

        return $this->redirect($this->generateUrl('user_profile_view', [
            "_locale"   => $request->getLocale(),
            "user_id"   => $following->getId(),
            "user_name" => $following->getUsername(),
        ]));
    }

    /**
     * @param int                    $user_id
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function unfollowAction(int $user_id, Request $request, EntityManagerInterface $entityManager)
    {
        /* who is following */
        /** @var User $follower */
        $follower = $this->getUser();
        /* who is followed */
        $following = $entityManager->getRepository('AppBundle:User')->find($user_id);

        if (!$following instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $found = false;
        foreach ($follower->getFollowing() as $user) {
            if ($user->getId() === $following->getId()) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $follower->removeFollowing($following);
            $entityManager->flush();
        }

        return $this->redirect($this->generateUrl('user_profile_view', [
            "_locale"   => $request->getLocale(),
            "user_id"   => $following->getId(),
            "user_name" => $following->getUsername(),
        ]));
    }

    /**
     * @param int                    $page
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     */
    public function usercommentsAction(int $page, Request $request, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPrivate();

        /** @var User $user */
        $user = $this->getUser();

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        $dbh = $entityManager->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
				c.id,
				c.text,
				c.date_creation,
				d.id decklist_id,
				d.name decklist_name,
				d.prettyname decklist_prettyname
				from comment c
				join decklist d on c.decklist_id=d.id
				where c.user_id=?
				order by date_creation desc
				limit $start, $limit",
            [
                $user->getId(),
            ]
        )
                        ->fetchAll(\PDO::FETCH_ASSOC);

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetch(\PDO::FETCH_NUM)[0];

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page

        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = [
                "numero"  => $page,
                "url"     => $this->generateUrl($route, [
                    "page" => $page,
                ]),
                "current" => $page == $currpage,
            ];
        }

        return $this->render('/Default/usercomments.html.twig', [
            'user'     => $user,
            'comments' => $comments,
            'url'      => $request
                ->getRequestUri(),
            'route'    => $route,
            'pages'    => $pages,
            'prevurl'  => $currpage == 1 ? null : $this->generateUrl($route, [
                "page" => $prevpage,
            ]),
            'nexturl'  => $currpage == $nbpages ? null : $this->generateUrl($route, [
                "page" => $nextpage,
            ]),
        ], $response);
    }

    /**
     * @param int                    $page
     * @param Request                $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     */
    public function commentsAction(int $page, Request $request, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('short_cache'));

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        $dbh = $entityManager->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
				c.id,
				c.text,
				c.date_creation,
				d.id decklist_id,
				d.name decklist_name,
				d.prettyname decklist_prettyname,
				u.id user_id,
				u.username author
				from comment c
				join decklist d on c.decklist_id=d.id
				join user u on c.user_id=u.id
				order by date_creation desc
				limit $start, $limit",
            []
        )->fetchAll(\PDO::FETCH_ASSOC);

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetch(\PDO::FETCH_NUM)[0];

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page

        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = [
                "numero"  => $page,
                "url"     => $this->generateUrl($route, [
                    "page" => $page,
                ]),
                "current" => $page == $currpage,
            ];
        }

        return $this->render('/Default/allcomments.html.twig', [
            'comments' => $comments,
            'url'      => $request
                ->getRequestUri(),
            'route'    => $route,
            'pages'    => $pages,
            'prevurl'  => $currpage == 1 ? null : $this->generateUrl($route, [
                "page" => $prevpage,
            ]),
            'nexturl'  => $currpage == $nbpages ? null : $this->generateUrl($route, [
                "page" => $nextpage,
            ]),
        ], $response);
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     */
    public function donatorsAction(EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('short_cache'));

        $dbh = $entityManager->getConnection();

        $users = $dbh->executeQuery("SELECT * FROM user WHERE donation>0 ORDER BY donation DESC, username", [])->fetchAll(\PDO::FETCH_ASSOC);

        return $this->render('/Default/donators.html.twig', [
            'pagetitle' => 'The Gracious Donators',
            'donators'  => $users,
        ], $response);
    }

    /**
     * @param int                    $days
     * @param EntityManagerInterface $entityManager
     * @param ActivityHelper         $activityHelper
     * @return Response
     *
     * @IsGranted("IS_AUTHENTICATED_REMEMBERED")
     */
    public function activityAction(int $days, EntityManagerInterface $entityManager, ActivityHelper $activityHelper)
    {
        $response = new Response();
        $response->setPrivate();
        $response->setMaxAge($this->getParameter('short_cache'));

        // max number of displayed items for each category
        $max_items = 30;

        $items = $activityHelper->getItems($this->getUser(), $max_items, $days);
        $items_by_day = $activityHelper->sortByDay($items);

        // recording date of activity check
        $this->getUser()->setLastActivityCheck(new \DateTime());
        $entityManager->flush();

        return $this->render('/Activity/activity.html.twig', [
            'pagetitle'    => 'Activity',
            'items_by_day' => $items_by_day,
            'max'          => $days,
        ], $response);
    }

    /**
     * @param int                    $decklist_id
     * @param int                    $status
     * @param int|null               $modflag_id
     * @param EntityManagerInterface $entityManager
     * @param ModerationHelper       $moderationHelper
     * @return JsonResponse
     *
     * @IsGranted("ROLE_MODERATOR")
     */
    public function moderateAction(int $decklist_id, int $status, int $modflag_id = null, EntityManagerInterface $entityManager, ModerationHelper $moderationHelper)
    {
        $response = new Response();
        $response->setPrivate();
        $response->setMaxAge($this->getParameter('short_cache'));

        /** @var Decklist $decklist */
        $decklist = $entityManager->getRepository('AppBundle:Decklist')->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException();
        }

        $moderationHelper->changeStatus($this->getUser(), $decklist, $status, $modflag_id);

        $entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}
