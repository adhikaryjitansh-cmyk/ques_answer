<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Question Answer Extractor</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen text-gray-800 p-8">
    <div class="max-w-4xl mx-auto space-y-8">
        
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">QA Extraction System</h1>
            <p class="text-gray-500 mt-2">Upload your mixed-language PDF and let the AI extract questions + answers to the database.</p>
        </div>

        <!-- Upload Form -->
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <h2 class="text-xl font-semibold mb-4 border-b pb-2">1. Upload PDF</h2>
            <form id="uploadForm" class="space-y-4">
                <div class="flex items-center space-x-4 border-2 border-dashed border-gray-300 p-6 rounded hover:border-blue-500 transition">
                    <input type="file" id="pdfFile" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" />
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded shadow hover:bg-blue-700 transition font-semibold min-w-[120px]">Upload</button>
                </div>
                <div id="uploadStatus" class="text-sm hidden p-3 rounded mt-2"></div>

                <!-- Progress Bar -->
                <div id="progressContainer" class="hidden mt-4">
                    <div class="flex justify-between text-sm mb-1">
                        <span id="progressText" class="font-medium text-blue-700">Processing...</span>
                        <span id="progressPercent" class="font-medium text-blue-700">0%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Queston List Viewer -->
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h2 class="text-xl font-semibold">2. View Extracted Database</h2>
                <div class="flex space-x-2">
                    <input type="text" id="searchInput" placeholder="Search exact question..." class="border border-gray-300 rounded px-3 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    <button id="refreshBtn" class="bg-gray-200 hover:bg-gray-300 px-4 py-1 rounded text-sm font-medium transition">Refresh List</button>
                </div>
            </div>
            
            <div id="questionsList" class="space-y-4">
                <div class="text-gray-500 text-center py-8">Loading questions...</div>
            </div>
        </div>
    </div>

    <script>
        // Upload Handle
        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const fileInput = document.getElementById('pdfFile');
            const statusDiv = document.getElementById('uploadStatus');
            const progressContainer = document.getElementById('progressContainer');
            
            if(!fileInput.files.length) {
                alert("Please select a file first.");
                return;
            }

            const formData = new FormData();
            formData.append('pdf', fileInput.files[0]);

            statusDiv.className = "text-sm p-3 rounded bg-yellow-100 text-yellow-800 block mt-2";
            statusDiv.innerText = "Uploading to server... please wait.";
            progressContainer.classList.add('hidden');

            try {
                const response = await fetch('{{ url("/api/upload-pdf") }}', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'text/event-stream'
                    }
                });
                
                if(!response.ok) {
                    statusDiv.className = "text-sm p-3 rounded bg-red-100 text-red-800 block mt-2";
                    statusDiv.innerText = "Error: Upload failed with status " + response.status;
                    return;
                }

                statusDiv.className = "text-sm p-3 rounded bg-green-100 text-green-800 block mt-2";
                statusDiv.innerText = "Upload successful! Extraction started instantly...";
                fileInput.value = '';

                progressContainer.classList.remove('hidden');
                document.getElementById('progressBar').style.width = '0%';
                document.getElementById('progressText').innerText = 'Initializing stream...';
                document.getElementById('progressPercent').innerText = '0%';

                const reader = response.body.getReader();
                const decoder = new TextDecoder("utf-8");
                let done = false;

                while (!done) {
                    const { value, done: readerDone } = await reader.read();
                    done = readerDone;
                    if (value) {
                        const chunk = decoder.decode(value, {stream: true});
                        const events = chunk.split('\n\n');
                        for (let ev of events) {
                            if (ev.trim().startsWith('data:')) {
                                try {
                                    const rawJson = ev.replace(/^data:\s*/, '').trim();
                                    if (!rawJson) continue;
                                    const data = JSON.parse(rawJson);
                                    
                                    const progressBar = document.getElementById('progressBar');
                                    const progressText = document.getElementById('progressText');
                                    const progressPercent = document.getElementById('progressPercent');
                                    
                                    if(data.progress !== undefined) {
                                        progressBar.style.width = data.progress + '%';
                                        progressPercent.innerText = data.progress + '%';
                                    }
                                    if(data.message) {
                                        progressText.innerText = data.message;
                                    }
                                    
                                    if (data.status === 'completed') {
                                        progressBar.classList.replace('bg-blue-600', 'bg-green-600');
                                        fetchQuestions();
                                        done = true;
                                    } else if (data.status === 'error') {
                                        progressBar.classList.replace('bg-blue-600', 'bg-red-600');
                                        done = true;
                                    } else {
                                        // Update UI periodically if new questions arrived
                                        fetchQuestions();
                                    }
                                } catch(e) {
                                    console.error("JSON Error parsing chunk:", e);
                                }
                            }
                        }
                    }
                }
            } catch (err) {
                statusDiv.className = "text-sm p-3 rounded bg-red-100 text-red-800 block mt-2";
                statusDiv.innerText = "Network Error: " + err.message;
            }
        });

        // Load Questions Handle
        let currentSearch = "";

        document.getElementById('searchInput').addEventListener('input', (e) => {
            currentSearch = e.target.value;
            // simple debounce
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                fetchQuestions();
            }, 500);
        });

        document.getElementById('refreshBtn').addEventListener('click', () => {
             fetchQuestions();
        });

        async function fetchQuestions() {
            const listDiv = document.getElementById('questionsList');
            try {
                const query = currentSearch ? `?search=${encodeURIComponent(currentSearch)}` : '';
                const response = await fetch('{{ url("/api/questions") }}' + query);
                const results = await response.json();
                
                listDiv.innerHTML = '';
                
                if(!results.data || results.data.length === 0) {
                    listDiv.innerHTML = '<div class="text-center py-8 text-gray-400">No questions found in database.</div>';
                    return;
                }

                results.data.forEach(q => {
                    const el = document.createElement('div');
                    el.className = "border border-gray-100 p-4 rounded bg-gray-50 flex flex-col";
                    el.innerHTML = `
                        <div class="flex justify-between items-start mb-2">
                            <span class="inline-block bg-purple-100 text-purple-800 text-xs px-2 py-1 rounded font-semibold capitalize tracking-wide">
                                Ch: ${escapeHtml(q.chapter || 'N/A')} | Lang: ${escapeHtml(q.language)}
                            </span>
                            ${q.has_image ? '<span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded font-semibold">🖼️ Has Diagram</span>' : ''}
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-gray-800">${escapeHtml(q.question)}</h3>
                        <p class="text-gray-700 whitespace-pre-wrap">${escapeHtml(q.answer)}</p>
                        ${q.has_image && q.image_path ? `<img src="${escapeHtml(q.image_path)}" class="mt-4 max-w-[300px] border rounded" />` : ''}
                    `;
                    listDiv.appendChild(el);
                });

            } catch (err) {
                listDiv.innerHTML = `<div class="text-red-500 py-4">Failed to load questions: ${err.message}</div>`;
            }
        }

        function escapeHtml(unsafe) {
            if(!unsafe) return '';
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        }

        // Initial Load
        fetchQuestions();
    </script>
</body>
</html>
