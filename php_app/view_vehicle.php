<?php
include 'config.php';

if (!isset($_GET['id'])) {
    header('Location: vehicles.php');
    exit;
}

$id = intval($_GET['id']);

// Get vehicle details
$sql = "SELECT v.*, c.company_name, c.company_code, b.branch_name, b.branch_code 
        FROM vehicles v
        LEFT JOIN companies c ON v.company_id = c.id
        LEFT JOIN branches b ON v.branch_id = b.id
        WHERE v.id = $id";
$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) == 0) {
    header('Location: vehicles.php');
    exit;
}

$vehicle = mysqli_fetch_assoc($result);

include 'header.php';
?>

<!-- Vehicle View Page -->
<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">Vehicle Details</h2>
            <p class="page-subtitle"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></p>
        </div>
        <a href="vehicles.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Vehicles
        </a>
    </div>
</div>

<!-- Vehicle Information Cards -->
<div class="info-grid">
    <!-- Basic Information Card -->
    <div class="info-card">
        <div class="card-header">
            <i class="fa-solid fa-truck"></i>
            <h3>Vehicle Information</h3>
        </div>
        <div class="info-rows">
            <div class="info-row">
                <span class="info-label">Vehicle Number:</span>
                <span class="info-value"><strong><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Owner:</span>
                <span class="info-value"><?php echo htmlspecialchars($vehicle['owner']) ?: '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Size:</span>
                <span class="info-value"><?php echo htmlspecialchars($vehicle['size']) ?: '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Engine Capacity:</span>
                <span class="info-value"><?php echo htmlspecialchars($vehicle['engine_capacity']) ?: '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Tyre Size:</span>
                <span class="info-value"><?php echo htmlspecialchars($vehicle['tyre_size']) ?: '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Wheel Type:</span>
                <span class="info-value">
                    <?php if ($vehicle['wheel_type']): ?>
                        <span class="badge badge-wheel"><?php echo ucfirst($vehicle['wheel_type']); ?> Wheel</span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value">
                    <?php if ($vehicle['active']): ?>
                        <span class="badge badge-success">
                            <i class="fa-solid fa-circle-check"></i> Active
                        </span>
                    <?php else: ?>
                        <span class="badge badge-inactive">
                            <i class="fa-solid fa-circle-xmark"></i> Inactive
                        </span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Company & Branch Card -->
    <div class="info-card">
        <div class="card-header">
            <i class="fa-solid fa-building"></i>
            <h3>Company & Branch</h3>
        </div>
        <div class="info-rows">
            <div class="info-row">
                <span class="info-label">Company:</span>
                <span class="info-value">
                    <?php if ($vehicle['company_name']): ?>
                        <strong><?php echo htmlspecialchars($vehicle['company_code']); ?></strong><br>
                        <small><?php echo htmlspecialchars($vehicle['company_name']); ?></small>
                    <?php else: ?>
                        <span style="color: #999;">Not assigned</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Branch:</span>
                <span class="info-value">
                    <?php if ($vehicle['branch_name']): ?>
                        <strong><?php echo htmlspecialchars($vehicle['branch_code']); ?></strong><br>
                        <small><?php echo htmlspecialchars($vehicle['branch_name']); ?></small>
                    <?php else: ?>
                        <span style="color: #999;">Not assigned</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Created:</span>
                <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($vehicle['created_at'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Last Updated:</span>
                <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($vehicle['updated_at'])); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Documents Section -->
<div class="content-card">
    <div class="card-header">
        <i class="fa-solid fa-file-lines"></i>
        <h3>Documents (Scan Copy)</h3>
    </div>
    
    <div class="documents-grid">
        <div class="document-item">
            <div class="document-header">
                <i class="fa-solid fa-book"></i>
                <span>Book Copy</span>
            </div>
            <?php if ($vehicle['book_copy'] && file_exists($vehicle['book_copy'])): ?>
                <a href="<?php echo $vehicle['book_copy']; ?>" target="_blank" class="btn-doc-view">
                    <i class="fa-solid fa-eye"></i> View Document
                </a>
            <?php else: ?>
                <p class="no-document">No document uploaded</p>
            <?php endif; ?>
        </div>

        <div class="document-item">
            <div class="document-header">
                <i class="fa-solid fa-file-invoice"></i>
                <span>Revenue Licence</span>
            </div>
            <?php if ($vehicle['revenue_licence'] && file_exists($vehicle['revenue_licence'])): ?>
                <a href="<?php echo $vehicle['revenue_licence']; ?>" target="_blank" class="btn-doc-view">
                    <i class="fa-solid fa-eye"></i> View Document
                </a>
            <?php else: ?>
                <p class="no-document">No document uploaded</p>
            <?php endif; ?>
        </div>

        <div class="document-item">
            <div class="document-header">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Insurance</span>
            </div>
            <?php if ($vehicle['insurance'] && file_exists($vehicle['insurance'])): ?>
                <a href="<?php echo $vehicle['insurance']; ?>" target="_blank" class="btn-doc-view">
                    <i class="fa-solid fa-eye"></i> View Document
                </a>
            <?php else: ?>
                <p class="no-document">No document uploaded</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lorry Images Section -->
<div class="content-card">
    <div class="card-header">
        <i class="fa-solid fa-images"></i>
        <h3>Lorry Images</h3>
    </div>
    
    <div class="images-grid">
        <?php 
        $images = [
            'front' => ['label' => 'Front', 'field' => 'lorry_image_front'],
            'rear' => ['label' => 'Rear', 'field' => 'lorry_image_rear'],
            'left' => ['label' => 'Left Side', 'field' => 'lorry_image_left'],
            'right' => ['label' => 'Right Side', 'field' => 'lorry_image_right']
        ];
        
        foreach ($images as $key => $image):
        ?>
        <div class="image-item">
            <div class="image-header"><?php echo $image['label']; ?></div>
            <?php if ($vehicle[$image['field']] && file_exists($vehicle[$image['field']])): ?>
                <div class="image-preview">
                    <img src="<?php echo $vehicle[$image['field']]; ?>" alt="<?php echo $image['label']; ?>" onclick="openImageModal(this.src)">
                </div>
            <?php else: ?>
                <div class="no-image">
                    <i class="fa-solid fa-image"></i>
                    <p>No image uploaded</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Action Buttons -->
<div style="margin-top: 24px; display: flex; gap: 12px;">
    <a href="vehicles.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i>
        Back to List
    </a>
    <a href="#" onclick="editVehicleFromView(<?php echo $vehicle['id']; ?>)" class="btn btn-primary">
        <i class="fa-solid fa-pen"></i>
        Edit Vehicle
    </a>
</div>

<!-- Image Modal -->
<div id="imageModal" class="image-modal" onclick="closeImageModal()">
    <span class="image-modal-close">&times;</span>
    <img class="image-modal-content" id="modalImage">
</div>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.info-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    overflow: hidden;
}

.card-header {
    background: #fafafa;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header i {
    color: #666666;
    font-size: 18px;
}

.card-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

.info-rows {
    padding: 20px;
}

.info-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 16px;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 13px;
    color: #666666;
    font-weight: 500;
}

.info-value {
    font-size: 14px;
    color: #333333;
}

/* Documents Grid */
.documents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding: 20px;
}

.document-item {
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.document-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 12px;
    font-weight: 600;
    color: #333333;
}

.document-header i {
    color: #666666;
}

.btn-doc-view {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #000000;
    color: #ffffff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s;
}

.btn-doc-view:hover {
    background: #333333;
    transform: translateY(-2px);
}

.no-document {
    color: #999999;
    font-size: 13px;
    margin: 0;
}

/* Images Grid */
.images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding: 20px;
}

.image-item {
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    overflow: hidden;
}

.image-header {
    background: #fafafa;
    padding: 12px;
    font-weight: 600;
    font-size: 13px;
    color: #333333;
    text-align: center;
    border-bottom: 1px solid #e5e5e5;
}

.image-preview {
    padding: 12px;
    background: #fafafa;
}

.image-preview img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.3s;
}

.image-preview img:hover {
    transform: scale(1.05);
}

.no-image {
    padding: 40px 20px;
    text-align: center;
    color: #999999;
}

.no-image i {
    font-size: 48px;
    margin-bottom: 10px;
    opacity: 0.3;
}

.no-image p {
    font-size: 13px;
    margin: 0;
}

/* Image Modal */
.image-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    padding-top: 50px;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.9);
}

.image-modal-content {
    margin: auto;
    display: block;
    max-width: 90%;
    max-height: 80vh;
    animation: zoom 0.3s;
}

@keyframes zoom {
    from {transform: scale(0.7)}
    to {transform: scale(1)}
}

.image-modal-close {
    position: absolute;
    top: 15px;
    right: 35px;
    color: #f1f1f1;
    font-size: 40px;
    font-weight: bold;
    transition: 0.3s;
    cursor: pointer;
}

.image-modal-close:hover {
    color: #bbb;
}

/* Badge Styles */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge i {
    font-size: 10px;
}

.badge-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-inactive {
    background: #fafafa;
    color: #666666;
    border: 1px solid #e5e5e5;
}

.badge-wheel {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Responsive */
@media (max-width: 768px) {
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .info-row {
        grid-template-columns: 120px 1fr;
        gap: 12px;
    }
    
    .documents-grid,
    .images-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function openImageModal(src) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.style.display = 'block';
    modalImg.src = src;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = 'none';
}

function editVehicleFromView(id) {
    window.location.href = 'vehicles.php?edit=' + id;
}
</script>

<?php include 'footer.php'; ?>
