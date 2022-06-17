<?php

namespace App\Helper;

use App\Entity\CollectPointType;
use App\Entity\DecisionTree;
use App\Entity\DecisionTreeStatus;
use App\Entity\DiagnosticAnswer;
use App\Entity\DiagnosticQuestion;
use App\Entity\DiagnosticQuestionType;
use App\Entity\DiagnosticResult;
use App\Entity\DiagnosticSolution;
use App\Entity\DiagnosticSolutionFlag;
use App\Entity\DiagnosticSolutionResult;
use App\Entity\DiagnosticSolutionStatus;
use App\Entity\DiagnosticSolutionStatusLabel;
use App\Entity\DiagnosticStep;
use App\Entity\LocalCollectivity;
use App\Entity\UserFrontRole;
use App\Repository\DiagnosticAnswerRepository;
use App\Repository\DiagnosticResultRepository;
use App\Repository\DiagnosticSolutionRepository;
use App\Repository\DiagnosticSolutionStatusLabelRepository;
use App\Repository\DiagnosticSolutionStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

class DiagnosticHelper
{
    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param DecisionTree $decisionTree
     * @param int $stepNumber
     * @return array
     * @throws Exception
     */
    public function getDiagnosticStep(DecisionTree $decisionTree, int $stepNumber, UserInterface $connectedUser): array
    {
        $em = $this->entityManager;

        $diagnosticStepRepository = $em->getRepository(DiagnosticStep::class);
        $diagnosticQuestionRepository = $em->getRepository(DiagnosticQuestion::class);

        $step = $diagnosticStepRepository->findOneBy(['step_number' => $stepNumber]);

        if (($step instanceof DiagnosticStep) === false) {
            throw new BadRequestHttpException(
                'Erreur avec le numéro de l\'étape du diagnostic : ' . $stepNumber
            );
        }

        $diagnosticQuestions = $diagnosticQuestionRepository->findBy(
            ['diagnostic_step' => $step, 'diagnostic_question_parent' => null],
            ['sorting' => 'asc']
        );

        if (empty($diagnosticQuestions)) {
            throw new NotFoundHttpException(
                'Aucune question liée à l\'étape ' . $stepNumber . ' du diagnostic'
            );
        }

        $readonly = true;
        $editable = false;

        if (($connectedUser instanceof LocalCollectivity) === true) {
            if (
                $connectedUser->getUserFrontRole()->getCode() == UserFrontRole::COLLECTIVITY_USER_ROLE &&
                $decisionTree->getDecisionTreeStatus()?->getCode() == DecisionTreeStatus::STATUS_DIAGNOSTIC_EN_COURS['code']
            ) {
                $readonly = false;
                $editable = true;
            }
        }

        $questions = [];

        /** @var DiagnosticQuestion $diagnosticQuestion */
        foreach ($diagnosticQuestions as $diagnosticQuestion) {
            if ($diagnosticQuestion->getDiagnosticQuestionType()?->getLabel() == DiagnosticQuestionType::TYPE_OTHER) {
                $diagnosticQuestionFirstChilds = $diagnosticQuestionRepository->findBy(
                    ['diagnostic_step' => $step, 'diagnostic_question_parent' => $diagnosticQuestion],
                    ['sorting' => 'asc']
                );
                if (!empty($diagnosticQuestionFirstChilds)) {
                    $tab = [];
                    foreach ($diagnosticQuestionFirstChilds as $diagnosticQuestionFirstChild) {
                        $diagnosticQuestionSecondChilds = $diagnosticQuestionRepository->findBy(
                            ['diagnostic_step' => $step, 'diagnostic_question_parent' => $diagnosticQuestionFirstChild],
                            ['sorting' => 'asc']
                        );
                        if (!empty($diagnosticQuestionSecondChilds)) {
                            $tabSecond = [];
                            /** @var DiagnosticQuestion $diagnosticQuestionSecondChild */
                            foreach ($diagnosticQuestionSecondChilds as $diagnosticQuestionSecondChild) {
                                $answers = $this->getAnswersForOneQuestion($diagnosticQuestionSecondChild, $decisionTree);
                                if (
                                    $diagnosticQuestionSecondChild->getDiagnosticQuestionType()->getLabel() == DiagnosticQuestionType::TYPE_CHOICE
                                ) {
                                    if (in_array($diagnosticQuestionSecondChild->getCode(), ['5FM8', '5HM8', '5EM8'])) {
                                        $readonly = true;
                                    }

                                    //If multiple answer : expanded = true
                                    $tabSecond[] = [
                                        'id' => $diagnosticQuestionSecondChild->getId(),
                                        'code' => $diagnosticQuestionSecondChild->getCode(),
                                        'type' => $diagnosticQuestionSecondChild->getDiagnosticQuestionType()?->getLabel(),
                                        'multiple' => $diagnosticQuestionSecondChild->getHasMultipleAnswer(),
                                        'expanded' => $diagnosticQuestionSecondChild->getHasMultipleAnswer(),
                                        'label' => $diagnosticQuestionSecondChild->getLabel(),
                                        'answers' => $answers['tab'],
                                        'readonly' => $readonly,
                                        'sorting' => $diagnosticQuestion->getSorting(),
                                    ];
                                } else {
                                    $tabSecond[] = [
                                        'id' => $diagnosticQuestionSecondChild->getId(),
                                        'code' => $diagnosticQuestionSecondChild->getCode(),
                                        'type' => $diagnosticQuestionSecondChild->getDiagnosticQuestionType()?->getLabel(),
                                        'multiline' => true,
                                        'label' => $diagnosticQuestionSecondChild->getLabel(),
                                        'answer_text' => $answers['text'],
                                        'readonly' => $readonly,
                                        'sorting' => $diagnosticQuestion->getSorting(),
                                    ];
                                }
                            }
                            $tab[] = [
                                'id' => $diagnosticQuestionFirstChild->getId(),
                                'type' => $diagnosticQuestionFirstChild->getDiagnosticQuestionType()->getLabel(),
                                'label' => $diagnosticQuestionFirstChild->getLabel(),
                                'childs' => $tabSecond
                            ];
                        }
                    }
                    $questions[] = [
                        'id' => $diagnosticQuestion->getId(),
                        'type' => $diagnosticQuestion->getDiagnosticQuestionType()->getLabel(),
                        'label' => $diagnosticQuestion->getLabel(),
                        'childs' => $tab,
                    ];
                }
            } else {
                $answers = $this->getAnswersForOneQuestion($diagnosticQuestion, $decisionTree);
                if (
                    $diagnosticQuestion->getDiagnosticQuestionType()->getLabel() == DiagnosticQuestionType::TYPE_CHOICE
                ) {
                    //If multiple answer : expanded = true
                    $questions[] = [
                        'id' => $diagnosticQuestion->getId(),
                        'code' => $diagnosticQuestion->getCode(),
                        'type' => $diagnosticQuestion->getDiagnosticQuestionType()?->getLabel(),
                        'multiple' => $diagnosticQuestion->getHasMultipleAnswer(),
                        'expanded' => $diagnosticQuestion->getHasMultipleAnswer(),
                        'label' => $diagnosticQuestion->getLabel(),
                        'answers' => $answers['tab'],
                        'readonly' => $readonly,
                    ];
                } else {
                    $questions[] = [
                        'id' => $diagnosticQuestion->getId(),
                        'code' => $diagnosticQuestion->getCode(),
                        'type' => $diagnosticQuestion->getDiagnosticQuestionType()?->getLabel(),
                        'multiline' => true,
                        'label' => $diagnosticQuestion->getLabel(),
                        'answer_text' => $answers['text'],
                        'readonly' => $readonly,
                    ];
                }
            }
        }

        if ($stepNumber == DiagnosticStep::STEP_4) {
            if (($decisionTree instanceof DecisionTree)) {
                $comment = $decisionTree->getDiagnosticComment();
            }
            $questions[] = [
                'diagnostic_comment' => $comment ?? null,
                'readonly' => $readonly,
            ];
        }

        $type = [];
        $collectPointType = $decisionTree->getCollectPoint()?->getCollectPointType();
        if (!is_null($collectPointType)) {
            /** @var CollectPointType $collectPointType */
            $type = [
                'id' => $collectPointType->getId(),
                'label' => $collectPointType->getLabel(),
            ];
        }

        $return['decision_tree'] = [
            'id' => $decisionTree->getId(),
            'label' => $decisionTree->getLabel(),
            'collect_point' => [
                'id' => $decisionTree->getCollectPoint()->getId(),
                'code' => $decisionTree->getCollectPoint()->getCode(),
                'name' => $decisionTree->getCollectPoint()->getName(),
                'collect_point_type' => $type,
            ],
            'editable' => $editable,
        ];

        $return['questions'] = $questions;

        return $return;
    }

    /**
     * @param DiagnosticQuestion|null $diagnosticQuestion
     * @param DecisionTree $decisionTree
     * @return array
     */
    public function getAnswersForOneQuestion(?DiagnosticQuestion $diagnosticQuestion, DecisionTree $decisionTree): array
    {
        $oldAnswers = [];

        $answerText = null;

        $em = $this->entityManager;

        $diagnosticAnswerRepository = $em->getRepository(DiagnosticAnswer::class);
        $diagnosticResultRepository = $em->getRepository(DiagnosticResult::class);

        $answers = $diagnosticAnswerRepository->findBy(
            ['diagnostic_question' => $diagnosticQuestion],
            ['sorting' => 'asc']
        );

        $diagnosticResults = $diagnosticResultRepository->findBy(
            ['decision_tree' => $decisionTree, 'diagnostic_question' => $diagnosticQuestion]
        );
        if (!empty($diagnosticResults)) {
            foreach ($diagnosticResults as $diagnosticResult) {
                if (!is_null($diagnosticResult->getDiagnosticAnswer())) {
                    $oldAnswers[] = $diagnosticResult->getDiagnosticAnswer()->getId();
                } else {
                    $answerText = $diagnosticResult->getRemark();
                }
            }
        }

        $answerTab = [];

        $alreadySelected = false;

        /** @var DiagnosticAnswer $answer */
        foreach ($answers as $answer) {
            $is_selected = false;
            if (in_array($answer->getId(), $oldAnswers)) {
                $alreadySelected = true;
                $is_selected = true;
            }

            $disabled = false;

            if (in_array($answer->getCode(), ['5FM8', '5HM8', '5EM8'])) {
                $disabled = true;
            }

            $answerTab[] = [
                'id' => $answer->getId(),
                'code' => $answer->getCode(),
                'label' => $answer->getLabel(),
                'is_selected' => $is_selected,
                'reset_all_answers' => $answer->getResetAllAnswer(),
                'disabled' => $disabled,
                'sorting' => $answer->getSorting(),
            ];
        }

        if (!empty($answerTab)) {
            if (in_array($diagnosticQuestion->getCode(), ['5FE', '5HE', '5PE', '5EE'])) {
                $answerTab[] = [
                    'id' => null,
                    'code' => null,
                    'label' => '',
                    'is_selected' => !$alreadySelected,
                    'reset_all_answers' => false,
                    'disabled' => $disabled,
                    'sorting' => -1,
                ];
            }
        }

        return ['tab' => $answerTab, 'text' => $answerText];
    }

    /**
     * @param array $questions
     * @param DecisionTree $decisionTree
     * @param int $stepNumber
     * @throws Exception
     */
    public function insertAnswerForDecisionTree(array $questions, DecisionTree $decisionTree, int $stepNumber, bool $isDraft)
    {
        $em = $this->entityManager;

        $diagnosticStepRepository = $em->getRepository(DiagnosticStep::class);
        $diagnosticQuestionRepository = $em->getRepository(DiagnosticQuestion::class);
        $diagnosticAnswerRepository = $em->getRepository(DiagnosticAnswer::class);

        $diagnosticStep = $diagnosticStepRepository->getOneByStepNumber($stepNumber);
        if (!empty($questions)) {
            $flagStorageMethod = !($stepNumber === 4);
            foreach ($questions as $question) {
                $questionObject = $diagnosticQuestionRepository->find($question['question_id']);
                if (($questionObject instanceof DiagnosticQuestion) === false) {
                    throw new NotFoundHttpException(
                        'La question n\'existe pas en base de données, ID : ' . $question['question_id']
                    );
                }
                if ($questionObject->getDiagnosticQuestionType()->getLabel() == DiagnosticQuestionType::TYPE_TEXT) {
                    $diagnosticResult = (new DiagnosticResult())
                        ->setDecisionTree($decisionTree)
                        ->setDiagnosticQuestion($questionObject)
                        ->setDiagnosticAnswer(null)
                        ->setRemark($question['answer_text'])
                        ->setDiagnosticStep($diagnosticStep)
                    ;

                    $this->entityManager->persist($diagnosticResult);
                } elseif (!empty($question['answers'])) {
                    foreach ($question['answers'] as $answerId) {
                        if (is_null($answerId)) {
                            throw new UnprocessableEntityHttpException('Vous n\'avez pas répondu à toutes les questions');
                        }
                        $answerObject = $diagnosticAnswerRepository->findOneById($answerId);
                        if (($answerObject instanceof DiagnosticAnswer) === false) {
                            throw new NotFoundHttpException(
                                'La réponse n\'existe pas en base de données, ID : ' . $answerId
                            );
                        }
                        $diagnosticResult = (new DiagnosticResult())
                            ->setDecisionTree($decisionTree)
                            ->setDiagnosticQuestion($questionObject)
                            ->setDiagnosticAnswer($answerObject)
                            ->setRemark(null)
                            ->setDiagnosticStep($diagnosticStep)
                        ;
                        $this->entityManager->persist($diagnosticResult);

                        if (in_array($questionObject->getCode(), ['5FM', '5HM', '5EM', '5PM'])) {
                            $flagStorageMethod = true;
                        }
                    }
                } elseif (
                    $isDraft === false
                    && $questionObject->getCode() !== '5PS'
                    && !in_array($questionObject->getCode(), ['5FM', '5HM', '5EM', '5PM'])
                ) {
                    throw new UnprocessableEntityHttpException('Vous n\'avez pas répondu à toutes les questions');
                }
            }
            // there no answer for 'Mode de Stockage'
            if (!$flagStorageMethod) {
                throw new UnprocessableEntityHttpException('Vous n\'avez pas répondu à toutes les questions');
            }
            $this->entityManager->flush();
        } else {
            throw new BadRequestHttpException('Aucune question passée en paramètres');
        }
    }

    /**
     * @param array $questions
     * @param DecisionTree $decisionTree
     * @param $stepNumber
     * @return array
     * @throws Exception
     */
    public function searchAnswerToDelete(array $questions, DecisionTree $decisionTree, $stepNumber, bool $isDraft = false): array
    {
        $em = $this->entityManager;

        $diagnosticStepRepository = $em->getRepository(DiagnosticStep::class);
        $diagnosticResultRepository = $em->getRepository(DiagnosticResult::class);

        $diagnosticStep = $diagnosticStepRepository->getOneByStepNumber($stepNumber);
        $diagnosticResults = $diagnosticResultRepository->findBy(
            ['decision_tree' => $decisionTree, 'diagnostic_step' => $diagnosticStep]
        );

        if (!empty($diagnosticResults)) {
            foreach ($diagnosticResults as $diagnosticResult) {
                $toDelete[] = $diagnosticResult->getId();
            }
        }

        $this->insertAnswerForDecisionTree(
            $questions,
            $decisionTree,
            $stepNumber,
            $isDraft
        );

        return $toDelete ?? [];
    }

    /**
     * @param array $diagnosticResults
     */
    public function deleteOldDiagnosticResult(array $diagnosticResults)
    {
        $em = $this->entityManager;
        $diagnosticResultRepository = $em->getRepository(DiagnosticResult::class);

        foreach ($diagnosticResults as $diagnosticResultId) {
            $diagnosticResult = $diagnosticResultRepository->find($diagnosticResultId);
            $em->remove($diagnosticResult);
        }
        $em->flush();
        $em->clear();
    }

    public function getMarquageGEM(DecisionTree $decisionTree)
    {
        $em = $this->entityManager;

        $diagnosticSolution = $em->getRepository(DiagnosticSolution::class)
            ->getOneByLabel(DiagnosticSolution::MARQUAGE_GEM);

        $diagnosticSolutionResult = $em->getRepository(DiagnosticSolutionResult::class)->findOneBy([
            'decision_tree' => $decisionTree,
            'diagnostic_solution' => $diagnosticSolution
        ]);

        if (($diagnosticSolutionResult instanceof DiagnosticSolutionResult) === false) {
            throw(new Exception(
                'La solution Marquage GEM n\'existe pas pour l\'arbre ' . $decisionTree->getLabel()
            ));
        }

        $marquageGemStatusOk = $em->getRepository(DiagnosticSolutionStatus::class)
            ->getIdStatusMarquageGemOk();

        $marquageGemStatusNok = $em->getRepository(DiagnosticSolutionStatus::class)
            ->getIdsStatusMarquageGemNok();

        if ($diagnosticSolutionResult->getDiagnosticSolutionStatus()->getId() == $marquageGemStatusOk) {
            return true;
        } elseif (in_array($diagnosticSolutionResult->getDiagnosticSolutionStatus()->getId(), $marquageGemStatusNok)) {
            $diagnosticSolutionFlag = $em->getRepository(DiagnosticSolutionFlag::class)
                ->getOneByCode(DiagnosticSolutionFlag::FLAG_RETENUE_VALIDEE['code']);

            if ($diagnosticSolutionResult->getDiagnosticSolutionFlag()->getId() == $diagnosticSolutionFlag->getId()) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * @param DecisionTree $decisionTree
     * @param DiagnosticAnswer $searchAnswer
     * @return bool
     */
    public function answerExist(DecisionTree $decisionTree, DiagnosticAnswer $searchAnswer): bool
    {
        $em = $this->entityManager;
        /** @var DiagnosticResultRepository $diagnosticResultRepository */
        $diagnosticResultRepository = $em->getRepository(DiagnosticResult::class);

        $diagnosticResult = $diagnosticResultRepository->findOneBy(
            ['decision_tree' => $decisionTree, 'diagnostic_answer' => $searchAnswer]
        );

        if (($diagnosticResult instanceof DiagnosticResult) === true) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param DecisionTree $decisionTree
     * @param DiagnosticSolution $diagnosticSolution
     * @param DiagnosticSolutionStatus $diagnosticSolutionStatus
     */
    public function insertSolutionLine(
        DecisionTree $decisionTree,
        DiagnosticSolution $diagnosticSolution,
        DiagnosticSolutionStatus &$diagnosticSolutionStatus
    ) {
        if (!is_null($diagnosticSolutionStatus)) {
            $em = $this->entityManager;

            $diagnosticSolutionFlag = $em->getRepository(DiagnosticSolutionFlag::class)
                ->getOneByCode(DiagnosticSolutionFlag::FLAG_NON_RETENUE['code']);

            $diagnosticSolutionResult = (new DiagnosticSolutionResult())
                ->setDecisionTree($decisionTree)
                ->setDiagnosticSolution($diagnosticSolution)
                ->setDiagnosticSolutionStatus($diagnosticSolutionStatus)
                ->setDiagnosticSolutionFlag($diagnosticSolutionFlag)
            ;

            $em->persist($diagnosticSolutionResult);
            $em->flush();

            $diagnosticSolutionStatus = null;
        }
    }

    /**
     * @param DecisionTree $decisionTree
     * @throws Exception
     */
    public function generateDiagnosticSolution(DecisionTree $decisionTree)
    {
        $em = $this->entityManager;

        /** @var DiagnosticAnswerRepository $diagnosticAnswerRepository */
        $diagnosticAnswerRepository = $em->getRepository(DiagnosticAnswer::class);
        /** @var DiagnosticSolutionRepository $diagnosticSolutionRepository */
        $diagnosticSolutionRepository = $em->getRepository(DiagnosticSolution::class);
        /** @var DiagnosticSolutionStatusRepository $diagnosticSolutionStatusRepository */
        $diagnosticSolutionStatusRepository = $em->getRepository(DiagnosticSolutionStatus::class);
        /** @var DiagnosticSolutionStatusLabelRepository $diagnosticSolutionStatusLabelRepository */
        $diagnosticSolutionStatusLabelRepository = $em->getRepository(DiagnosticSolutionStatusLabel::class);

        //Solutions Status
        $diagnosticSolutionStatusLabelAtteintEfficace = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_CRITERE_ATTEINT_ET_EFFICACE['code']);
        $diagnosticSolutionStatusLabelAtteintPeuEfficace = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_CRITERE_ATTEINT_PEU_EFFICACE['code']);
        $diagnosticSolutionStatusLabelPrerequis = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_PREREQUIS['code']);
        $diagnosticSolutionStatusLabelEnVigueur = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_EN_VIGUEUR['code']);
        $diagnosticSolutionStatusLabelRecommande = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_RECOMMANDEE['code']);
        $diagnosticSolutionStatusLabelInutile = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_INUTILE['code']);
        $diagnosticSolutionStatusLabelImpossible = $diagnosticSolutionStatusLabelRepository
            ->getOneByCode(DiagnosticSolutionStatusLabel::STATUS_IMPOSSIBLE['code']);

        //Answers
        $answers = $diagnosticAnswerRepository->getAllAnswers();

        /*
         * Prérequis Validation du marquage du GEM
         */
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::MARQUAGE_GEM);

        if (
            $this->answerExist($decisionTree, $answers['4E1']) === true
            && $this->answerExist($decisionTree, $answers['4E5']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelAtteintEfficace
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['4E1']) === true
            || $this->answerExist($decisionTree, $answers['4E5']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelAtteintPeuEfficace
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelPrerequis
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        /*
         * Solutions proposées pour protéger les DEEE
         */
        //Contrôler l'interdiction de la récupération
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::INTERDICTION_RECUPERATION);
        if (
            $this->answerExist($decisionTree, $answers['2B2']) === true
            && (
                $this->answerExist($decisionTree, $answers['2A2']) === true
                || $this->answerExist($decisionTree, $answers['2A3']) === true
                || $this->answerExist($decisionTree, $answers['2A4']) === true
                || $this->answerExist($decisionTree, $answers['4A1']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['2A1']) === true
            || $this->answerExist($decisionTree, $answers['2B1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } else {
            $diagnosticSolutionStatus = null;
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Ajuster la fréquence d'enlèvement des DEEE
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::FREQUENCE_ENLEVEMENT_DEEE);
        if (
            (
                $this->answerExist($decisionTree, $answers['5HE2']) === true
                && $this->answerExist($decisionTree, $answers['3D7']) === true
            )
            || (
                $this->answerExist($decisionTree, $answers['5FE2']) === true
                && $this->answerExist($decisionTree, $answers['3D6']) === true
            )
            || (
                $this->answerExist($decisionTree, $answers['5EE2']) === true
                && $this->answerExist($decisionTree, $answers['3D9']) === true
            )
            || (
                $this->answerExist($decisionTree, $answers['5PE2'])  === true
                && $this->answerExist($decisionTree, $answers['3D8']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['5HE1']) === true
            && $this->answerExist($decisionTree, $answers['5FE1']) === true
            && $this->answerExist($decisionTree, $answers['5PE1']) === true
            && $this->answerExist($decisionTree, $answers['5EE1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Stocker les DEEE dans un local en dur
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DEEE_LOCAL_DUR);

        if (
            $this->answerExist($decisionTree, $answers['5FM1']) === true
            && $this->answerExist($decisionTree, $answers['5HM1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            (
                (
                    $this->answerExist($decisionTree, $answers['5FM2']) === true
                    || $this->answerExist($decisionTree, $answers['5FM3']) === true
                    || $this->answerExist($decisionTree, $answers['5HM9']) === true
                )
                &&
                (
                    $this->answerExist($decisionTree, $answers['5HM2']) === true
                    || $this->answerExist($decisionTree, $answers['5HM3']) === true
                    || $this->answerExist($decisionTree, $answers['5HM9']) === true
                )
            ) || (
                $this->answerExist($decisionTree, $answers['3B1']) === true
                && $this->answerExist($decisionTree, $answers['3F1']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } elseif (!$this->answerExist($decisionTree, $answers['4F1']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelImpossible
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Stocker les DEEE en conteneur maritime
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DEEE_CONTENEUR_MARITIME);
        if (
            (
                $this->answerExist($decisionTree, $answers['5FM2']) === true
                || $this->answerExist($decisionTree, $answers['5FM3']) === true
            )
            && (
                (
                    $this->answerExist($decisionTree, $answers['5HM2']) === true
                    || $this->answerExist($decisionTree, $answers['5HM3']) === true
                )
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            (
                $this->answerExist($decisionTree, $answers['5FM1']) === true
                && $this->answerExist($decisionTree, $answers['5FM9']) === true
                && $this->answerExist($decisionTree, $answers['5HM1']) === true
                && $this->answerExist($decisionTree, $answers['5HM9']) === true
            )
            || (
                $this->answerExist($decisionTree, $answers['3B1']) === true
                && $this->answerExist($decisionTree, $answers['3F1']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } elseif (!$this->answerExist($decisionTree, $answers['4F2']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelImpossible
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Renforcer les systèmes de fermeture des locaux, conteneurs,…
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::RENFORCER_FERMETURE_LOCAUX);

        if (
            (
                !$this->answerExist($decisionTree, $answers['5FM1']) === true
                && !$this->answerExist($decisionTree, $answers['5FM2']) === true
                && !$this->answerExist($decisionTree, $answers['5FM3']) === true
                && !$this->answerExist($decisionTree, $answers['5FM9']) === true
            )
            && (
                !$this->answerExist($decisionTree, $answers['5HM1']) === true
                && !$this->answerExist($decisionTree, $answers['5HM2']) === true
                && !$this->answerExist($decisionTree, $answers['5HM3']) === true
                && !$this->answerExist($decisionTree, $answers['5HM9']) === true
            )
            && (
                !$this->answerExist($decisionTree, $answers['4F1']) === true
                && !$this->answerExist($decisionTree, $answers['4F2']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelImpossible
            );
        } elseif (
            (
                (
                    $this->answerExist($decisionTree, $answers['5FM1']) === true
                    || $this->answerExist($decisionTree, $answers['5FM2']) === true
                    || $this->answerExist($decisionTree, $answers['5FM3']) === true
                    || $this->answerExist($decisionTree, $answers['5FM9']) === true
                )
                && (
                    $this->answerExist($decisionTree, $answers['5FS10']) === true
                    || $this->answerExist($decisionTree, $answers['5FS6']) === true
                    || $this->answerExist($decisionTree, $answers['5FS5']) === true
                )
                && !$this->answerExist($decisionTree, $answers['5FS8']) === true
            )
            && (
            (
                    $this->answerExist($decisionTree, $answers['5HM1']) === true
                    || $this->answerExist($decisionTree, $answers['5HM2']) === true
                    || $this->answerExist($decisionTree, $answers['5HM3']) === true
                    || $this->answerExist($decisionTree, $answers['5HM9']) === true
                )
                && (
                    $this->answerExist($decisionTree, $answers['5FS10']) === true
                    || $this->answerExist($decisionTree, $answers['5FS6']) === true
                    || $this->answerExist($decisionTree, $answers['5FS5']) === true
                )
                && !$this->answerExist($decisionTree, $answers['5FS8']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            (
                $this->answerExist($decisionTree, $answers['3D6']) === true
                && (
                    $this->answerExist($decisionTree, $answers['5FM1']) === true
                    || $this->answerExist($decisionTree, $answers['5FM2']) === true
                    || $this->answerExist($decisionTree, $answers['5FM3']) === true
                    || $this->answerExist($decisionTree, $answers['5FM9']) === true
                )
            )
            || (
                $this->answerExist($decisionTree, $answers['3D7']) === true
                && (
                    $this->answerExist($decisionTree, $answers['5HM1']) === true
                    || $this->answerExist($decisionTree, $answers['5HM2']) === true
                    || $this->answerExist($decisionTree, $answers['5HM3']) === true
                    || $this->answerExist($decisionTree, $answers['5HM9']) === true
                )
            )
            || (
                $this->answerExist($decisionTree, $answers['3D7']) === true
                && (
                    $this->answerExist($decisionTree, $answers['5PM1']) === true
                    || $this->answerExist($decisionTree, $answers['5PM2']) === true
                    || $this->answerExist($decisionTree, $answers['5PM3']) === true
                    || $this->answerExist($decisionTree, $answers['5PM9']) === true
                )
            )
            || (
                $this->answerExist($decisionTree, $answers['3D9']) === true
                && (
                    $this->answerExist($decisionTree, $answers['5EM1']) === true
                    || $this->answerExist($decisionTree, $answers['5EM2']) === true
                    || $this->answerExist($decisionTree, $answers['5EM3']) === true
                    || $this->answerExist($decisionTree, $answers['5EM9']) === true
                )
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Mettre l'aire DEEE sous le contrôle visuel des agents d'accueil
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::AIRE_DEEE_CONTROLE_VISUEL);

        if ($this->answerExist($decisionTree, $answers['4B1']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            !$this->answerExist($decisionTree, $answers['3A1']) === true
            && !$this->answerExist($decisionTree, $answers['3C1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Mettre en place un suivi de stock DEEE
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::SUIVI_STOCK_DEEE);

        if ($this->answerExist($decisionTree, $answers['4D4']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['4D1']) === true
            || $this->answerExist($decisionTree, $answers['4D2']) === true
            || $this->answerExist($decisionTree, $answers['4D3']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = null;
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Réserver aux agents d'accueil l'accès aux DEEE mis en sûreté
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::AGENT_MISE_SURETE_DEEE);

        if ($this->answerExist($decisionTree, $answers['4C2']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            (
                (
                    $this->answerExist($decisionTree, $answers['5FM1']) === true
                    || $this->answerExist($decisionTree, $answers['5FM2']) === true
                    || $this->answerExist($decisionTree, $answers['5FM3']) === true
                    || $this->answerExist($decisionTree, $answers['5FM9']) === true
                )
                && (
                    $this->answerExist($decisionTree, $answers['5HM1']) === true
                    || $this->answerExist($decisionTree, $answers['5HM2']) === true
                    || $this->answerExist($decisionTree, $answers['5HM3']) === true
                    || $this->answerExist($decisionTree, $answers['5HM9']) === true
                )
                && (
                    $this->answerExist($decisionTree, $answers['5EM1']) === true
                    || $this->answerExist($decisionTree, $answers['5EM2']) === true
                    || $this->answerExist($decisionTree, $answers['5EM3']) === true
                    || $this->answerExist($decisionTree, $answers['5EM9']) === true
                )
                && (
                    $this->answerExist($decisionTree, $answers['5PM1']) === true
                    || $this->answerExist($decisionTree, $answers['5PM2']) === true
                    || $this->answerExist($decisionTree, $answers['5PM3']) === true
                    || $this->answerExist($decisionTree, $answers['5PM8']) === true
                    || $this->answerExist($decisionTree, $answers['5PM9']) === true
                )
            )
            || (
                !$this->answerExist($decisionTree, $answers['3A1']) === true
                && !$this->answerExist($decisionTree, $answers['3C1']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Déposer et suivre les plaintes
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DEPOSER_SUIVRE_PLAINTES);

        if (
            $this->answerExist($decisionTree, $answers['2H6']) === true
            && $this->answerExist($decisionTree, $answers['2H7']) === true
            && (
                $this->answerExist($decisionTree, $answers['2H4']) === true
                || $this->answerExist($decisionTree, $answers['2H5']) === true
            )
            && !$this->answerExist($decisionTree, $answers['2G7']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        /*
         * Solutions proposées pour mettre en sûreté le site
         */
        //Doter les agents d'accueil d'un dispositif d'alerte
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DISPOSITIF_ALERTE);

        if (
            $this->answerExist($decisionTree, $answers['1F1']) === true
            || $this->answerExist($decisionTree, $answers['1F2']) === true
            || $this->answerExist($decisionTree, $answers['1F3']) === true
            || $this->answerExist($decisionTree, $answers['1F4']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Faire réaliser un diagnostic sûreté par un référent sûreté
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DIAGNOSTIC_SURETE);

        if ($this->answerExist($decisionTree, $answers['2G8']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Mettre en place une vidéoprotection
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::VIDEOPROTECTION);

        if (
            $this->answerExist($decisionTree, $answers['2C3']) === true
            || $this->answerExist($decisionTree, $answers['2C7']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['3B1']) === true
            && $this->answerExist($decisionTree, $answers['3F1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Mettre en place un détecteur d'intrusion
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::DETECTEUR_INTRUSION);

        if (
            $this->answerExist($decisionTree, $answers['2C4']) === true
            || $this->answerExist($decisionTree, $answers['2C5']) === true
            || $this->answerExist($decisionTree, $answers['2C6']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['3B1']) === true
            && $this->answerExist($decisionTree, $answers['3F1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Mettre en place une ronde de surveillance
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::RONDE_SURVEILLANCE);

        if (
            $this->answerExist($decisionTree, $answers['2F1']) === true
            || $this->answerExist($decisionTree, $answers['2F2']) === true
            || $this->answerExist($decisionTree, $answers['2F3']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['2C4']) === true
            || $this->answerExist($decisionTree, $answers['2C5']) === true
            || $this->answerExist($decisionTree, $answers['2C6']) === true
            || (
                $this->answerExist($decisionTree, $answers['2C7']) === true
                && $this->answerExist($decisionTree, $answers['2E6']) === true
            )
            || (
                $this->answerExist($decisionTree, $answers['3B1']) === true
                && $this->answerExist($decisionTree, $answers['3F1']) === true
            )
            || $this->answerExist($decisionTree, $answers['3B2']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Réglementer le stationnement aux abords du site
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::STATIONNEMENT);

        if ($this->answerExist($decisionTree, $answers['3A3']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Eclairer le site et ses abords
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::ECLAIRAGE_SITE);

        if (
            (
                $this->answerExist($decisionTree, $answers['3B1']) === true
                && $this->answerExist($decisionTree, $answers['3F1']) === true
            )
            || $this->answerExist($decisionTree, $answers['1A1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } elseif ($this->answerExist($decisionTree, $answers['2E7']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif ($this->answerExist($decisionTree, $answers['2E5']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } elseif (!$this->answerExist($decisionTree, $answers['2E3']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['2E6']) === true
            && (
                $this->answerExist($decisionTree, $answers['2C3']) === true
                || $this->answerExist($decisionTree, $answers['2C7']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Assurer la propreté du site et de ses abords
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::PROPRETE_SITE);

        if (
            $this->answerExist($decisionTree, $answers['3B1']) === true
            && $this->answerExist($decisionTree, $answers['3F1']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['1D1']) === true
            || $this->answerExist($decisionTree, $answers['1D2']) === true
            || $this->answerExist($decisionTree, $answers['1D3']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);

        //Optimiser les dispositifs de protection du site
        $diagnosticSolution = $diagnosticSolutionRepository
            ->getOneByLabel(DiagnosticSolution::OPTIMISER_PROTECTION_SITE);

        if (
            (
                $this->answerExist($decisionTree, $answers['3B1']) === true
                && $this->answerExist($decisionTree, $answers['3F1']) === true
            )
            || (
                !$this->answerExist($decisionTree, $answers['3C2']) === true
                && !$this->answerExist($decisionTree, $answers['3C3']) === true
                && !$this->answerExist($decisionTree, $answers['3C4']) === true
            )
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelInutile
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['1D1']) === true
            || $this->answerExist($decisionTree, $answers['1D2']) === true
            || $this->answerExist($decisionTree, $answers['1D3']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (
            $this->answerExist($decisionTree, $answers['1B1']) === true
            || $this->answerExist($decisionTree, $answers['1B5']) === true
            || $this->answerExist($decisionTree, $answers['1B8']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (!$this->answerExist($decisionTree, $answers['1C2']) === true) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        } elseif (
            (
                $this->answerExist($decisionTree, $answers['1B2']) === true
                || $this->answerExist($decisionTree, $answers['1B3']) === true
                || $this->answerExist($decisionTree, $answers['1B4']) === true
            )
            && $this->answerExist($decisionTree, $answers['1C2']) === true
            && $this->answerExist($decisionTree, $answers['1D4']) === true
        ) {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelEnVigueur
            );
        } else {
            $diagnosticSolutionStatus = $diagnosticSolutionStatusRepository->getOneBySolutionAndStatusLabel(
                $diagnosticSolution,
                $diagnosticSolutionStatusLabelRecommande
            );
        }

        $this->insertSolutionLine($decisionTree, $diagnosticSolution, $diagnosticSolutionStatus);
    }

    /**
     * @param DecisionTree $newDecisionTree
     * @param DecisionTree $oldDecisionTree
     */
    public function copyAnswerFromOldTree(DecisionTree $newDecisionTree, DecisionTree $oldDecisionTree): void
    {
        $em = $this->entityManager;
        $diagnosticResults = $em->getRepository(DiagnosticResult::class)
            ->findBy(['decision_tree' => $oldDecisionTree]);

        if (!empty($diagnosticResults)) {
            foreach ($diagnosticResults as $diagnosticResult) {
                /** @var DiagnosticResult $newDiagnosticResult */
                $newDiagnosticResult = clone $diagnosticResult;
                $newDiagnosticResult->setDecisionTree($newDecisionTree);
                $em->persist($newDiagnosticResult);
            }
            $em->flush();
        }
    }
}
