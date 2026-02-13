<?php
// report_generator/modals.php
?>

<!-- Page Manager Modal -->
<div id="pageManagerModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3><i class="fas fa-layer-group"></i> Slide Manager</h3>
            <button class="modal-close" onclick="closeModal('pageManagerModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="manager-tabs">
                <button class="tab-btn active" onclick="switchTab('allSlides')">All Slides</button>
                <button class="tab-btn" onclick="switchTab('recentChanges')">Recent Changes</button>
                <button class="tab-btn" onclick="switchTab('templates')">Templates</button>
                <button class="tab-btn" onclick="switchTab('images')">Images</button>
            </div>
            
            <div class="tab-content active" id="allSlides">
                <div class="search-box">
                    <input type="text" placeholder="Search slides..." id="slideSearch" onkeyup="searchSlides()">
                    <select id="sortSlides" onchange="sortSlides()">
                        <option value="number">By Number</option>
                        <option value="title">By Title</option>
                        <option value="updated">Recently Updated</option>
                    </select>
                </div>
                <div class="slides-grid" id="slidesGrid">
                    <!-- Slides will be loaded here -->
                </div>
                <div class="modal-actions">
                    <button class="btn btn-primary" onclick="duplicateSlide()">
                        <i class="fas fa-copy"></i> Duplicate Current
                    </button>
                    <button class="btn btn-danger" onclick="deleteSlide()">
                        <i class="fas fa-trash"></i> Delete Current
                    </button>
                    <button class="btn" onclick="exportAllSlides()">
                        <i class="fas fa-download"></i> Export All
                    </button>
                </div>
            </div>
            
            <div class="tab-content" id="recentChanges">
                <div class="changes-list" id="changesList">
                    <!-- Changes will be loaded here -->
                </div>
            </div>
            
            <div class="tab-content" id="templates">
                <div class="templates-grid">
                    <div class="template-item" onclick="applyTemplate('title')">
                        <div class="template-preview title-template"></div>
                        <h4>Title Slide</h4>
                        <p>Cover page with title and subtitle</p>
                    </div>
                    <div class="template-item" onclick="applyTemplate('content')">
                        <div class="template-preview content-template"></div>
                        <h4>Content Slide</h4>
                        <p>Text with bullet points</p>
                    </div>
                    <div class="template-item" onclick="applyTemplate('chart')">
                        <div class="template-preview chart-template"></div>
                        <h4>Chart Slide</h4>
                        <p>Placeholder for charts/graphs</p>
                    </div>
                    <div class="template-item" onclick="applyTemplate('summary')">
                        <div class="template-preview summary-template"></div>
                        <h4>Summary Slide</h4>
                        <p>Key takeaways and conclusions</p>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="images">
                <div class="images-manager">
                    <div class="upload-area" onclick="document.getElementById('batchUpload').click()">
                        <i class="fas fa-cloud-upload-alt fa-3x"></i>
                        <p>Drop images here or click to upload</p>
                        <input type="file" id="batchUpload" multiple accept="image/*" style="display: none;" onchange="batchUploadImages(this.files)">
                    </div>
                    <div class="images-grid" id="imagesGrid">
                        <!-- Images will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Image Editor Modal -->
<div id="imageEditorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-crop"></i> Edit Image</h3>
            <button class="modal-close" onclick="closeModal('imageEditorModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="image-editor">
                <div class="image-preview" id="imagePreviewContainer">
                    <img id="imagePreview" src="" alt="Preview">
                </div>
                <div class="editor-tools">
                    <div class="tool-group">
                        <label>Size:</label>
                        <input type="range" id="imageSize" min="10" max="100" value="50" oninput="resizePreview(this.value)">
                        <span id="sizeValue">50%</span>
                    </div>
                    <div class="tool-group">
                        <label>Rotation:</label>
                        <button onclick="rotateImage(-90)"><i class="fas fa-undo"></i></button>
                        <button onclick="rotateImage(90)"><i class="fas fa-redo"></i></button>
                        <input type="number" id="rotateAngle" value="0" onchange="rotateImage(this.value)">
                    </div>
                    <div class="tool-group">
                        <label>Alt Text:</label>
                        <input type="text" id="imageAlt" placeholder="Description for accessibility">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="applyImageEdit()">Apply</button>
            <button class="btn" onclick="closeModal('imageEditorModal')">Cancel</button>
        </div>
    </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Slide History</h3>
            <button class="modal-close" onclick="closeModal('historyModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="history-list" id="historyList">
                <!-- History entries will be loaded here -->
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn" onclick="restoreVersion()">
                <i class="fas fa-undo"></i> Restore This Version
            </button>
        </div>
    </div>
</div>