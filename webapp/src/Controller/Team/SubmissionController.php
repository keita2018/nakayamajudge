<?php declare(strict_types=1);

namespace App\Controller\Team;

use App\Controller\BaseController;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\Language;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\Testcase;
use App\Form\Type\SubmitProblemType;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\SubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class SubmissionController
 *
 * @Route("/team")
 * @IsGranted("ROLE_TEAM")
 * @Security("user.getTeam() !== null", message="You do not have a team associated with your account.")
 * @package App\Controller\Team
 */
class SubmissionController extends BaseController
{
    protected EntityManagerInterface $em;
    protected SubmissionService $submissionService;
    protected DOMJudgeService $dj;
    protected ConfigurationService $config;
    protected FormFactoryInterface $formFactory;

    const NEVER_SHOW_COMPILE_OUTPUT = 0;
    const ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR = 1;
    const ALWAYS_SHOW_COMPILE_OUTPUT = 2;

    public function __construct(
        EntityManagerInterface $em,
        SubmissionService $submissionService,
        DOMJudgeService $dj,
        ConfigurationService $config,
        FormFactoryInterface $formFactory
    ) {
        $this->em                = $em;
        $this->submissionService = $submissionService;
        $this->dj                = $dj;
        $this->config            = $config;
        $this->formFactory       = $formFactory;
    }

    /**
     * @Route("/submit/{problem}", name="team_submit")
     */
    public function createAction(Request $request, ?Problem $problem = null): Response
    {
        $user    = $this->dj->getUser();
        $team    = $user->getTeam();
        $contest = $this->dj->getCurrentContest($user->getTeam()->getTeamid());
        $data = [];
        if ($problem !== null) {
            $data['problem'] = $problem;
        }
        $form    = $this->formFactory
            ->createBuilder(SubmitProblemType::class, $data)
            ->setAction($this->generateUrl('team_submit'))
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($contest === null) {
                $this->addFlash('danger', 'No active contest');
            } elseif (!$this->dj->checkrole('jury') && !$contest->getFreezeData()->started()) {
                $this->addFlash('danger', 'Contest has not yet started');
            } else {
                /** @var Problem $problem */
                $problem = $form->get('problem')->getData();
                /** @var Language $language */
                $language = $form->get('language')->getData();
                /** @var UploadedFile[] $files */
                $files      = $form->get('code')->getData();
                if (!is_array($files)) {
                    $files = [$files];
                }
                // 追加: 貼り付けコードの取得
                $codeText = $form->has('code_text') ? (string)$form->get('code_text')->getData() : '';

                // ファイル未指定かつ貼り付けがある場合は、一時ファイルを作成して $files に入れる
                $needsPaste = (empty($files) || (count($files) === 1 && $files[0] === null)) && trim($codeText) !== '';
                $cleanupTmp = static function () {};
                if ($needsPaste) {
                    [$tmpPath, $tmpName] = $this->createTempSourceFromText($codeText, $language);
                    $files = [new UploadedFile($tmpPath, $tmpName, null, null, true)];
                    $cleanupTmp = static function () use ($tmpPath) { @unlink($tmpPath); };
                } elseif ((empty($files) || (count($files) === 1 && $files[0] === null)) && trim($codeText) === '') {
                    // どちらも空の場合はエラー
                    $this->addFlash('danger', 'Please upload a file/ZIP or paste your code.');
                    return $this->render('team/submit.html.twig', ['form' => $form->createView(), 'problem' => $problem]);
                }
                $entryPoint = $form->get('entry_point')->getData() ?: null;
                // $submission = $this->submissionService->submitSolution(
                //     $team, $this->dj->getUser(), $problem->getProbid(), $contest, $language, $files, 'team page', null,
                //     null, $entryPoint, null, null, $message
                // );
                try {
                    $submission = $this->submissionService->submitSolution(
                        $team, $this->dj->getUser(), $problem->getProbid(), $contest, $language, $files, 'team page',
                        null, null, $entryPoint, null, null, $message
                    );
                } finally {
                    // 貼り付け用の一時ファイルがあれば削除
                    $cleanupTmp();
                }

                if ($submission) {
                    $this->addFlash(
                        'success',
                        'Submission done! Watch for the verdict in the list below.'
                    );
                } else {
                    $this->addFlash('danger', $message);
                }
                return $this->redirectToRoute('team_index');
            }
        }

        $data = ['form' => $form->createView(), 'problem' => $problem];

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submit_modal.html.twig', $data);
        } else {
            return $this->render('team/submit.html.twig', $data);
        }
    }

    /**
     * @Route("/submission/{submitId<\d+>}", name="team_submission")
     * @throws NonUniqueResultException
     */
    public function viewAction(Request $request, int $submitId): Response
    {
        $verificationRequired = (bool)$this->config->get('verification_required');
        $showCompile      = $this->config->get('show_compile');
        $showSampleOutput = $this->config->get('show_sample_output');
        $allowDownload    = (bool)$this->config->get('allow_team_submission_download');
        $user             = $this->dj->getUser();
        $team             = $user->getTeam();
        $contest          = $this->dj->getCurrentContest($team->getTeamid());
        /** @var Judging $judging */
        $judging = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->join('j.submission', 's')
            ->join('s.contest_problem', 'cp')
            ->join('cp.problem', 'p')
            ->join('s.language', 'l')
            ->select('j', 's', 'cp', 'p', 'l')
            ->andWhere('j.submission = :submitId')
            ->andWhere('j.valid = 1')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        // Update seen status when viewing submission.
        if ($judging && $judging->getSubmission()->getSubmittime() < $contest->getEndtime() &&
            (!$verificationRequired || $judging->getVerified())) {
            $judging->setSeen(true);
            $this->em->flush();
        }


        $allRuns = [];
        if ($judging !== null && $judging->getResult() !== 'compiler-error') {
            $rows = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                // 選択中の（=有効な）Judging に紐づく run をぶら下げる。出力は取らない
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->select('t', 'jr')
                ->andWhere('t.problem = :problem')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber')
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                if ($row instanceof Testcase) {
                    $tc = $row;
                } elseif (is_array($row)) {
                    $tc = $row['t'] ?? ($row[0] ?? null);
                } else {
                    $tc = null;
                }
                if ($tc instanceof Testcase) {
                    $allRuns[] = $tc;
                }
            }
        }

        $runs = [];
        if ($showSampleOutput && $judging && $judging->getResult() !== 'compiler-error') {
            $outputDisplayLimit    = (int)$this->config->get('output_display_limit');
            $outputTruncateMessage = sprintf("\n[output display truncated after %d B]\n", $outputDisplayLimit);

            $queryBuilder = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                ->join('t.content', 'tc')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->leftJoin('jr.output', 'jro')
                ->select('t', 'jr', 'tc')
                ->andWhere('t.problem = :problem')
                ->andWhere('t.sample = 1')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber');

            if ($outputDisplayLimit < 0) {
                $queryBuilder
                    ->addSelect('tc.output AS output_reference')
                    ->addSelect('jro.output_run AS output_run')
                    ->addSelect('jro.output_diff AS output_diff')
                    ->addSelect('jro.output_error AS output_error')
                    ->addSelect('jro.output_system AS output_system');
            } else {
                $queryBuilder
                    ->addSelect('TRUNCATE(tc.output, :outputDisplayLimit, :outputTruncateMessage) AS output_reference')
                    ->addSelect('TRUNCATE(jro.output_run, :outputDisplayLimit, :outputTruncateMessage) AS output_run')
                    ->addSelect('TRUNCATE(jro.output_diff, :outputDisplayLimit, :outputTruncateMessage) AS output_diff')
                    ->addSelect('TRUNCATE(jro.output_error, :outputDisplayLimit, :outputTruncateMessage) AS output_error')
                    ->addSelect('TRUNCATE(jro.output_system, :outputDisplayLimit, :outputTruncateMessage) AS output_system')
                    ->setParameter('outputDisplayLimit', $outputDisplayLimit)
                    ->setParameter('outputTruncateMessage', $outputTruncateMessage);
            }

            $runs = $queryBuilder
                ->getQuery()
                ->getResult();
        }

        $actuallyShowCompile = $showCompile == self::ALWAYS_SHOW_COMPILE_OUTPUT
            || ($showCompile == self::ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR && $judging->getResult() === 'compiler-error');

        $data = [
            'judging' => $judging,
            'verificationRequired' => $verificationRequired,
            'showCompile' => $actuallyShowCompile,
            'allowDownload' => $allowDownload,
            'showSampleOutput' => $showSampleOutput,
            'runs' => $runs,
            'allRuns' => $allRuns,
        ];
        if ($actuallyShowCompile) {
            $data['size'] = 'xl';
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submission_modal.html.twig', $data);
        } else {
            return $this->render('team/submission.html.twig', $data);
        }
    }

    /**
     * @Route("/submission/{submitId<\d+>}/download", name="team_submission_download")
     * @throws NonUniqueResultException
     */
    public function downloadAction(int $submitId): Response
    {
        $allowDownload = (bool)$this->config->get('allow_team_submission_download');
        if (!$allowDownload) {
            throw new NotFoundHttpException('Submission download not allowed');
        }

        $user = $this->dj->getUser();
        $team = $user->getTeam();
        /** @var Submission $submission */
        $submission = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->join('s.files', 'f')
            ->select('s, f')
            ->andWhere('s.submitid = :submitId')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        if ($submission === null) {
            throw new NotFoundHttpException(sprintf('Submission with ID \'%s\' not found',
                $submitId));
        }

        return $this->submissionService->getSubmissionZipResponse($submission);
    }

    private function createTempSourceFromText(string $code, Language $language): array
    {
        $ext = $this->guessExtensionForLanguage($language);
        $base = 'main' . $ext;
        $tmp = tempnam(sys_get_temp_dir(), 'djpaste_');
        // 拡張子を付けたいので rename
        $tmpWithExt = $tmp . $ext;
        @unlink($tmp);
        if (@file_put_contents($tmpWithExt, $code) === false) {
            throw new \RuntimeException('Failed to create a temporary source file.');
        }
        return [$tmpWithExt, $base];
    }

    /**
     * 言語から代表拡張子を推定（Languageに拡張子情報がある版はそれを優先）
     */
    private function guessExtensionForLanguage(Language $language): string
    {
        if (method_exists($language, 'getExtensions')) {
            $exts = $language->getExtensions();
            if (is_array($exts) && count($exts) > 0) {
                $first = ltrim((string)$exts[0], '.');
                return $first ? ('.' . $first) : '.txt';
            }
        }
        $key = strtolower((string)($language->getLangid() ?? $language->getName() ?? ''));
        $map = [
            'c' => '.c', 'cpp' => '.cpp', 'c++' => '.cpp', 'cc' => '.cc',
            'python' => '.py', 'python3' => '.py', 'pypy' => '.py',
            'java' => '.java', 'kotlin' => '.kt', 'rust' => '.rs',
            'go' => '.go', 'golang' => '.go', 'ruby' => '.rb',
            'haskell' => '.hs', 'scala' => '.scala', 'swift' => '.swift',
            'csharp' => '.cs', 'cs' => '.cs', 'php' => '.php',
            'dart' => '.dart', 'js' => '.js', 'node' => '.js', 'typescript' => '.ts',
        ];
        foreach ($map as $k => $v) {
            if (strpos($key, $k) !== false) return $v;
        }
        return '.txt';
    }
}
