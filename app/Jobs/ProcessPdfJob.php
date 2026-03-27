<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 14400; // 4 hours for huge PDFs

    protected $pdfPath;
    protected $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct($pdfPath, $jobId = null)
    {
        $this->pdfPath = $pdfPath;
        $this->jobId = $jobId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting PDF processing for: " . $this->pdfPath . " with job_id: " . $this->jobId);

        if ($this->jobId) {
            \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                'status' => 'processing',
                'progress' => 5,
                'message' => 'Starting OCR and AI extraction...'
            ], 3600);
        }

        $pythonScript = base_path('python_scripts/process_pdf.py');
        $fullPdfPath = Storage::path($this->pdfPath);

        $process = new Process(['python', $pythonScript, $fullPdfPath]);
        $process->setTimeout(14400); // 4 hours
        
        try {
            // Run and continuously read the output
            $process->run(function ($type, $buffer) {
                // $buffer could contain multiple newlines
                $lines = explode("\n", $buffer);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Try parsing as JSON
                    $parsed = json_decode($line, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                        
                        // Check if it's a progress/chunk object meaning the page finished
                        if (isset($parsed['type']) && $parsed['type'] === 'progress') {
                            
                            // Insert any newly extracted questions from this chunk instantly
                            if (isset($parsed['data']) && is_array($parsed['data'])) {
                                foreach ($parsed['data'] as $item) {
                                    if (!isset($item['question']) || !isset($item['answer'])) {
                                        continue;
                                    }
                                    Question::create([
                                        'chapter' => $item['chapter'] ?? null,
                                        'question' => $item['question'],
                                        'answer' => $item['answer'],
                                        'language' => $item['language'] ?? 'mixed',
                                    ]);
                                }
                            }

                            // Update progress in Cache
                            if ($this->jobId) {
                                $current = intval($parsed['current']);
                                $total = intval($parsed['total']);
                                $percent = intval(($current / max($total, 1)) * 95); // Reserve 100 for finish
                                
                                \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                                    'status' => 'processing',
                                    'progress' => $percent,
                                    'message' => "Processing page {$current} of {$total}..."
                                ], 3600);
                            }

                        } elseif (isset($parsed['error'])) {
                            Log::error("Python script error emitted: " . $parsed['error']);
                            if ($this->jobId) {
                                \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                                    'status' => 'error',
                                    'progress' => 0,
                                    'message' => "Error: " . $parsed['error']
                                ], 3600);
                            }
                        }
                    } else {
                        // Regular print/logging from python/paddle
                        // Log::debug("Python: " . $line);
                    }
                }
            });

            if (!$process->isSuccessful()) {
                Log::error("Process failed: " . $process->getErrorOutput());
                if ($this->jobId) {
                    \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                        'status' => 'error',
                        'progress' => 0,
                        'message' => "Processing failed unexpectedly."
                    ], 3600);
                }
            } else {
                Log::info("Process completed successfully.");
                if ($this->jobId) {
                    \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                        'status' => 'completed',
                        'progress' => 100,
                        'message' => "Extracted all pages successfully!"
                    ], 3600);
                }
            }
        } catch (\Exception $e) {
            Log::error("Job crash: " . $e->getMessage());
            if ($this->jobId) {
                \Illuminate\Support\Facades\Cache::put("progress_{$this->jobId}", [
                    'status' => 'error',
                    'progress' => 0,
                    'message' => "System error occurred."
                ], 3600);
            }
        }
    }
}
