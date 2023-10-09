<?php

namespace Astrotomic\DeepFace;

use Astrotomic\DeepFace\Data\AnalyzeResult;
use Astrotomic\DeepFace\Data\ExtractFaceResult;
use Astrotomic\DeepFace\Data\FaceArea;
use Astrotomic\DeepFace\Data\VerifyResult;
use Astrotomic\DeepFace\Enums\AnalyzeAction;
use Astrotomic\DeepFace\Enums\Detector;
use Astrotomic\DeepFace\Enums\DistanceMetric;
use Astrotomic\DeepFace\Enums\Emotion;
use Astrotomic\DeepFace\Enums\Gender;
use Astrotomic\DeepFace\Enums\Model;
use Astrotomic\DeepFace\Enums\Normalization;
use Astrotomic\DeepFace\Enums\Race;
use BadMethodCallException;
use InvalidArgumentException;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DeepFace
{
    protected ?string $python = null;

    public function __construct(string $python = null)
    {
        $this->python = $python ?? (new ExecutableFinder)->find(
            name: 'python3',
            default: 'python3',
        );
    }

    public function verify(
        string $img1_path,
        string $img2_path,
        Model $model_name = Model::VGGFACE,
        Detector $detector_backend = Detector::OPENCV,
        DistanceMetric $distance_metric = DistanceMetric::COSINE,
        bool $enforce_detection = true,
        bool $align = true,
        Normalization $normalization = Normalization::BASE,
    ): VerifyResult {
        $img1 = new SplFileInfo($img1_path);
        $img2 = new SplFileInfo($img2_path);

        if (! $img1->isFile()) {
            throw new InvalidArgumentException("The path [{$img1_path}] for image#1 is not a file.");
        }
        if (! $img2->isFile()) {
            throw new InvalidArgumentException("The path [{$img2_path}] for image#2 is not a file.");
        }

        $output = $this->run(
            filepath: __DIR__.'/../scripts/verify.py',
            data: [
                '{{img1_path}}' => $img1->getRealPath(),
                '{{img2_path}}' => $img2->getRealPath(),
                '{{enforce_detection}}' => $enforce_detection ? 'True' : 'False',
                '{{align}}' => $align ? 'True' : 'False',
                '{{model_name}}' => $model_name->value,
                '{{detector_backend}}' => $detector_backend->value,
                '{{distance_metric}}' => $distance_metric->value,
                '{{normalization}}' => $normalization->value,
            ],
        );

        return new VerifyResult(
            verified: $output['verified'] === 'True',
            distance: $output['distance'],
            threshold: $output['threshold'],
            model: Model::from($output['model']),
            detector_backend: Detector::from($output['detector_backend']),
            similarity_metric: DistanceMetric::from($output['similarity_metric']),
            img1_path: $img1->getRealPath(),
            img1_facial_area: new FaceArea(...$output['facial_areas']['img1']),
            img2_path: $img2->getRealPath(),
            img2_facial_area: new FaceArea(...$output['facial_areas']['img2']),
            time: $output['time'],
        );
    }

    /**
     * @return AnalyzeResult[]
     */
    public function analyze(
        string $img_path,
        array $actions = [AnalyzeAction::EMOTION, AnalyzeAction::AGE, AnalyzeAction::RACE, AnalyzeAction::GENDER],
        bool $enforce_detection = true,
        Detector $detector_backend = Detector::OPENCV,
        bool $align = true,
        bool $silent = false,
    ): array {
        $img = new SplFileInfo($img_path);

        if (! $img->isFile()) {
            throw new InvalidArgumentException("The path [{$img_path}] for image is not a file.");
        }

        $actions = array_map(
            fn (mixed $action): AnalyzeAction => match (true) {
                is_string($action) => AnalyzeAction::from($action),
                $action instanceof AnalyzeAction => $action,
                default => throw new InvalidArgumentException('The action ['.gettype($action).'] provided is not a valid action type.'),
            },
            $actions
        );

        $output = $this->run(
            filepath: __DIR__.'/../scripts/analyze.py',
            data: [
                '{{img_path}}' => $img->getRealPath(),
                '{{actions}}' => '['.implode(',', array_map(fn (AnalyzeAction $action) => "'{$action->value}'", $actions)).']',
                '{{enforce_detection}}' => $enforce_detection ? 'True' : 'False',
                '{{detector_backend}}' => $detector_backend->value,
                '{{align}}' => $align ? 'True' : 'False',
                '{{silent}}' => $silent ? 'True' : 'False',
            ],
        );

        return array_map(
            fn (array $result) => new AnalyzeResult(
                region: new FaceArea(...$result['region']),
                emotion: $result['emotion'] ?? null,
                dominant_emotion: isset($result['dominant_emotion']) ? Emotion::from($result['dominant_emotion']) : null,
                age: $result['age'] ?? null,
                gender: $result['gender'] ?? null,
                dominant_gender: isset($result['dominant_gender']) ? Gender::from($result['dominant_gender']) : null,
                race: $result['race'] ?? null,
                dominant_race: isset($result['dominant_race']) ? Race::from($result['dominant_race']) : null,
            ),
            $output
        );
    }

    /**
     * @return ExtractFaceResult[]
     */
    public function extractFaces(
        string $img_path,
        array $target_size = [224, 224],
        Detector $detector_backend = Detector::OPENCV,
        bool $enforce_detection = true,
        bool $align = true,
        bool $grayscale = false,
    ): array {
        $img = new SplFileInfo($img_path);

        if (! $img->isFile()) {
            throw new InvalidArgumentException("The path [{$img_path}] for image is not a file.");
        }

        $output = $this->run(
            filepath: __DIR__.'/../scripts/extract_faces.py',
            data: [
                '{{img_path}}' => $img->getRealPath(),
                '{{target_size}}' => '['.implode(',', $target_size).']',
                '{{enforce_detection}}' => $enforce_detection ? 'True' : 'False',
                '{{detector_backend}}' => $detector_backend->value,
                '{{align}}' => $align ? 'True' : 'False',
                '{{grayscale}}' => $grayscale ? 'True' : 'False',
            ],
        );

        return array_map(
            fn (array $result) => new ExtractFaceResult(
                facial_area: new FaceArea(...$result['facial_area']),
                confidence: $result['confidence']
            ),
            $output
        );
    }

    protected function run(string $filepath, array $data): array
    {
        $script = $this->script($filepath, $data);
        $process = $this->process($script);

        $output = $process
            ->mustRun()
            ->getOutput();

        $lines = array_values(array_filter(explode(PHP_EOL, $output), function (string $line): bool {
            json_decode($line, true);

            return json_last_error() === JSON_ERROR_NONE;
        }));

        if (empty($lines)) {
            throw new BadMethodCallException('Python deepface script has not returned with any JSON.');
        }

        $json = $lines[0];

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function process(string $script): Process
    {
        $process = new Process([
            $this->python,
            '-c',
            $script,
        ]);

        return $process
            ->setTimeout(null)
            ->setIdleTimeout(60 * 5);
    }

    protected function script(string $filepath, $data): string
    {
        $template = file_get_contents($filepath);

        $script = trim(strtr($template, $data));

        return str_replace(PHP_EOL, ' ', $script);
    }
}