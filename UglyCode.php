<?php
namespace LogiCE\Cron\Job;

use LogiCE\Cron\Job;

class NewExerciseBeneficiaryProcess extends Job
{
    use \LogiCE\Settings\OptionalTrait;

    /** @var bool */
    protected $isEnabled = false;
    /** @var $impactAdministrator \LogiCE\Beneficiary\ProcessImpact\Administrator */
    private $impactAdministrator;
    /** @var \Project\Module\Proof\Proof\Model $proofModel  */
    private $proofModel;
    /** @var  \LogiCE\Model\Beneficiary $beneficiaryModel */
    private $beneficiaryModel;

    /**
     * @return \Project\Module\Proof\Proof\Model
     */
    public function getProofModel()
    {
        if ($this->proofModel == null) {
            $this->proofModel = new \Project\Module\Proof\Proof\Model();
        }
        return $this->proofModel;
    }

    /**
     * @param \Project\Module\Proof\Proof\Model $proofModel
     * @return $this
     */
    public function setProofModel(\Project\Module\Proof\Proof\Model $proofModel)
    {
        $this->proofModel = $proofModel;
        return $this;
    }

    /**
     * @param \LogiCE\Model\Beneficiary $beneficiaryModel
     * @return $this
     */
    public function setBeneficiaryModel(\LogiCE\Model\Beneficiary $beneficiaryModel)
    {
        $this->beneficiaryModel = $beneficiaryModel;
        return $this;
    }

    /**
     * @return \Project\Module\Proof\Proof
     */
    public function getProofApi()
    {
        return new \Project\Module\Proof\Proof();
    }

    /**
     * @param \LogiCE\Beneficiary\ProcessImpact\Administrator $impactAdministrator
     * @return $this
     */
    public function setImpactAdministrator(\LogiCE\Beneficiary\ProcessImpact\Administrator $impactAdministrator)
    {
        $this->impactAdministrator = $impactAdministrator;
        return $this;
    }

    public function getPeriodicity()
    {
        return '0 1 1 1 *';
    }

    private function processImpact(\LogiCE\Entity\Beneficiary $beneficiary, $templateProcess)
    {
        $impactAdministrator = \LogiCE\Beneficiary\ProcessImpact\Administrator::getInstance();
        $impactAdministrator->setBootstrap($this->getBootstrap());
        error_log( print_r( $beneficiary->getLastProcessusGlobalTemplate($this->getPreviousExercise()), true ) );

        $impactAdministrator->impactProcessus(
            $beneficiary->getLastProcessusGlobalTemplate($this->getPreviousExercise()),
            $templateProcess,
            array($beneficiary->getId()),
            $this->getCurrentExercise(),
            null,
            null,
            0,
            false,
            true
        );
    }

    public function process()
    {
        error_log("begin process()");

        $exercice = $this->getCurrentExercise();
        $lastYearExercice = $this->getPreviousExercise();
        $nextExercice = $exercice->getNextExercise();

        try {
            \LogiCE\Helper\Db::getInstance()->get()->beginTransaction();

            $sqlDeleteProcessusSnapshot = "DELETE FROM BENEFICIAIRES_PROCESSUS_SNAPSHOT";
            \LogiCE\Helper\Db::getInstance()->get()->exec($sqlDeleteProcessusSnapshot);

            $sqlFillProcessusSnapshot = "INSERT INTO BENEFICIAIRES_PROCESSUS_SNAPSHOT
                SELECT * FROM BENEFICIAIRES_PROCESSUS WHERE template_name = '".
                \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN.
                "' and exercice = ".$exercice->getId();
            \LogiCE\Helper\Db::getInstance()->get()->exec($sqlFillProcessusSnapshot);


            //Suppression des processus de beneficiaire pour l'exercice en cours afin des les ecraser
            $builder = \LogiCE\Helper\Db::getInstance()->get()->createQueryBuilder()
                ->delete(\BeneficiaireProcessus::getTableName())
                ->where('exercice = :exercice OR exercice = :nextExercice')
                ->setParameter('exercice', $exercice->getId())
                ->setParameter('nextExercice', $nextExercice->getId());
            $builder->execute();

            $beneficiaryModel = (new \LogiCE\Model\Beneficiary());
            $numberOfBeneficiaries = $beneficiaryModel->getTotalNumberOfBeneficiaries();

            error_log("begin step by step beneficiaries listing");

            error_log("total numberOfBeneficiaries : ");
            error_log($numberOfBeneficiaries);

            $maxResults = 4000 ;
            error_log("maxResults per select : ");
            error_log($maxResults);

            for ($firstResult = 0; $firstResult < $numberOfBeneficiaries; $firstResult += $maxResults) {

                error_log("firstResult : ");
                error_log($firstResult);

                $allBeneficiaries = $beneficiaryModel->listAllBeneficiary('*', true, array(), $firstResult, $maxResults);

                $sqlInsert = 'INSERT INTO ' . \BeneficiaireProcessus::getTableName() . ' (id_beneficiaire, template_name, exercice, date_changement) VALUES ';
                $sqlInsertRow = array();
                $logRightOpen = array();
                $logRightSuspended = array();
                $logRightCopy = array();
                /* // For CCGPF : set the OD without all proofs validated to BENEFICIARY_TEMPLATE_WAIT_PROFILE */
                $logWaitProfile = array();
                /* // For CCGPF : If the beneficiary is already completed for the new year, we don't copy suspended, blocked or closing statuses */
                $logDontCopy = array();

                foreach ($allBeneficiaries as $beneficiary) {
                    error_log("updating beneficiary : ".$beneficiary->getMatricule());

                    /** @var \LogiCE\Entity\Beneficiary $beneficiary */
                    $lastYearProcessus = $beneficiary->getLastProcessusGlobalTemplate($lastYearExercice);
                    $thisYearProcessus = $beneficiary->getLastProcessusSnapshotGlobalTemplate($exercice);
                    /* // For CCGPF : treat the OD like the AD
                    if ($beneficiary->isOd()) {
                        error_log("beneficiary->isOd()");
                        $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                        $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                        continue;
                    }
                    */
                    /* // For CCGPF : If the beneficiary is already completed for the new year, we don't copy anything*/
                    if (
                        $thisYearProcessus == \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN
                    ) {
                        error_log("thisYearProcessus == \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN");
                        $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $thisYearProcessus . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                        $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $thisYearProcessus . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                        $logDontCopy[] = $beneficiary->getMatricule();
                    } else {
                        $allProofAreValidated = $this->getProofApi()->areAllProofValidated($beneficiary, new \DateTime());
                        if (
                        in_array($lastYearProcessus, \LogiCE\Beneficiary\ProcessList::TEMPLATES_SHOWED_YELLOW_FAMILYCOMPOSITION, true)
                        ) {
                            error_log("lastYearProcessus in \LogiCE\Beneficiary\ProcessList::TEMPLATES_SHOWED_YELLOW_FAMILYCOMPOSITION");
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $logRightCopy[] = $beneficiary->getMatricule();
                        } else if (
                            /* // For CCGPF : set the OD without all proofs validated to BENEFICIARY_TEMPLATE_WAIT_PROFILE */
                            //$beneficiary->getIdNature() != \Nature::BENEFICIARY &&
                        $allProofAreValidated
                        ) {
                            error_log("beneficiary->getIdNature() != \Nature::BENEFICIARY &&
                        allProofAreValidated");
                            if ($lastYearProcessus == \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN) {
                                error_log("lastYearProcessus == \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN");
                                $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                                $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                                $logRightCopy[] = $beneficiary->getMatricule();
                            } else {
                                error_log("lastYearProcessus != \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN");
                                $this->processImpact($beneficiary, \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN);
                                $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_OPEN . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            }
                            $logRightOpen[] = $beneficiary->getMatricule();
                        } else if (
                            $beneficiary->getIdNature() != \Nature::BENEFICIARY &&
                            !in_array($lastYearProcessus, \LogiCE\Beneficiary\ProcessList::TEMPLATES_SHOWED_YELLOW_FAMILYCOMPOSITION, true)
                        ) {
                            error_log("beneficiary->getIdNature() != \Nature::BENEFICIARY &&
                        lastYearProcessus not in \LogiCE\Beneficiary\ProcessList::TEMPLATES_SHOWED_YELLOW_FAMILYCOMPOSITION");
                            $this->processImpact($beneficiary, \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_SUSPENDED);
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_RIGHTS_SUSPENDED . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $logRightSuspended[] = $beneficiary->getMatricule();
                        } else {
                            /* // For CCGPF : set the OD without all proofs validated to BENEFICIARY_TEMPLATE_WAIT_PROFILE */
                            /*
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . $lastYearProcessus . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $logRightCopy[] = $beneficiary->getMatricule();
                             */
                            error_log("beneficiary->getIdNature() == \Nature::BENEFICIARY &&
                        ! allProofAreValidated");
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_WAIT_PROFILE . '",' . $exercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $sqlInsertRow[] = ' (' . $beneficiary->getId() . ',"' . \LogiCE\Beneficiary\ProcessList::BENEFICIARY_TEMPLATE_WAIT_PROFILE . '",' . $nextExercice->getId() . ',\'' . date('Y-m-d H:i:s') . '\') ';
                            $logWaitProfile[] = $beneficiary->getMatricule();
                        }
                    }
                }
                if (!empty($sqlInsertRow)) {
                    $sqlInsert .= implode(',', $sqlInsertRow) . ';';
                    //error_log($sqlInsert);
                    \LogiCE\Helper\Db::getInstance()->get()->exec($sqlInsert);
                }
                $this->log(
                    \LogiCE\Logger::INFO,
                    'Right open',
                    $logRightOpen
                );
                $this->log(
                    \LogiCE\Logger::INFO,
                    'Right suspended',
                    $logRightSuspended
                );
                $this->log(
                    \LogiCE\Logger::INFO,
                    'Right copy',
                    $logRightCopy
                );
                /* // For CCGPF : set the OD without all proofs validated to BENEFICIARY_TEMPLATE_WAIT_PROFILE */
                $this->log(
                    \LogiCE\Logger::INFO,
                    'Wait Profile',
                    $logWaitProfile
                );
                /* // For CCGPF : If the beneficiary is already completed for the new year, we don't copy suspended, blocked or closing statuses */
                $this->log(
                    \LogiCE\Logger::INFO,
                    'Dont copy',
                    $logDontCopy
                );
            }
            \LogiCE\Helper\Db::getInstance()->get()->commit();
        } catch (\LogiCE\Cron\Exception $e) {
            error_log("Exception in process()");

            \LogiCE\Helper\Db::getInstance()->get()->rollBack();
            throw new \LogiCE\Cron\Exception($e->getMessage());
        }
        error_log("end process()");

    }
}
