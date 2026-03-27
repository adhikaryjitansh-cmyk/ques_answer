<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Question;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class QuestionController extends Controller
{
    /**
     * Search and Filter Questions
     */
    public function index(Request $request)
    {
        $query = Question::query();

        if ($request->has('search')) {
            $query->whereFullText('question', $request->search);
        }

        if ($request->has('chapter')) {
            $query->where('chapter', $request->chapter);
        }

        $cacheKey = 'questions_' . md5($request->fullUrl());
        $questions = Cache::remember($cacheKey, 60, function () use ($query) {
            return $query->latest()->paginate(20);
        });

        return response()->json($questions);
    }

    /**
     * Upload PDF and Process (Instantly, bypassing queues via streaming)
     */
    public function uploadPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:204800', // 200MB limit
        ]);

        $path = $request->file('pdf')->store('pdfs');

        // Close session write lock before starting heavy operations
        session_write_close();

        return response()->stream(function () use ($path) {
            $pythonScript = base_path('python_scripts/process_pdf.py');
            $fullPdfPath = Storage::path($path);
            
            $process = new \Symfony\Component\Process\Process(['C:\\Python313\\python.exe', $pythonScript, $fullPdfPath]);
            $process->setEnv([
                'PYTHONPATH' => 'C:\\Users\\web Capital\\AppData\\Roaming\\Python\\Python313\\site-packages;C:\\Python313\\Lib\\site-packages',
                'PATH' => getenv('PATH'),
                'USERPROFILE' => 'C:\\Users\\web Capital',
                'HOMEDRIVE' => 'C:',
                'HOMEPATH' => '\\Users\\web Capital',
                'TEMP' => sys_get_temp_dir(),
                'TMP' => sys_get_temp_dir(),
                'MODELSCOPE_CACHE' => storage_path('app/modelscope_cache'),
                'SYSTEMROOT' => getenv('SYSTEMROOT') ?: 'C:\\Windows',
            ]);
            $process->setTimeout(14400); // Allow hours to pass for massive PDFs without cutting off
            
            if (ob_get_level() > 0) ob_end_flush();
            echo "data: " . json_encode(['status' => 'init', 'message' => 'Upload successful! Starting 720p Image OCR extraction...']) . "\n\n";
            flush();
            
            try {
                $process->run(function ($type, $buffer) {
                    $lines = explode("\n", $buffer);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        $parsed = json_decode($line, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                            if (isset($parsed['type']) && $parsed['type'] === 'progress') {
                                if (isset($parsed['data']) && is_array($parsed['data'])) {
                                    foreach ($parsed['data'] as $item) {
                                        if (!isset($item['question']) || !isset($item['answer'])) continue;
                                        Question::create([
                                            'chapter' => $item['chapter'] ?? null,
                                            'question' => $item['question'],
                                            'answer' => $item['answer'],
                                            'language' => $item['language'] ?? 'mixed',
                                        ]);
                                    }
                                }

                                $current = intval($parsed['current']);
                                $total = intval($parsed['total']);
                                $percent = intval(($current / max($total, 1)) * 95);

                                echo "data: " . json_encode([
                                    'status' => 'processing',
                                    'progress' => $percent,
                                    'message' => "Extracted page {$current} of {$total}..."
                                ]) . "\n\n";
                                flush();

                            } elseif (isset($parsed['error'])) {
                                echo "data: " . json_encode(['status' => 'error', 'progress' => 0, 'message' => "Error: " . $parsed['error']]) . "\n\n";
                                flush();
                            }
                        }
                    }
                });

                if (!$process->isSuccessful()) {
                    echo "data: " . json_encode(['status' => 'error', 'message' => 'Processing failed unexpectedly: ' . $process->getErrorOutput()]) . "\n\n";
                } else {
                    echo "data: " . json_encode(['status' => 'completed', 'progress' => 100, 'message' => 'Extracted all pages successfully!']) . "\n\n";
                }
            } catch (\Exception $e) {
                echo "data: " . json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]) . "\n\n";
            }
            flush();

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no' // Prevent Nginx/Apache chunk buffering
        ]);
    }

    /**
     * Manually attach an image to a question
     */
    public function addImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $question = Question::findOrFail($id);
        
        $path = $request->file('image')->store('question_images', 'public');

        $question->update([
            'has_image' => true,
            'image_path' => Storage::url($path),
        ]);

        return response()->json([
            'message' => 'Image attached successfully.',
            'data' => $question
        ]);
    }
}
