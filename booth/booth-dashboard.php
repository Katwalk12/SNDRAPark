<?php
// Booth Teller Dashboard
// Simple standalone dashboard for scanning barcodes and handling booth flows.
require_once __DIR__ . '/../backend/config/db.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SNDRA Park — Booth Teller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="booth.css">
</head>
<body class="bg-white">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 text-primary">SNDRA Park — Booth Teller</h3>
        <div class="text-end">
            <small class="text-muted">Booth mode — USB barcode scanner (HID Keyboard)</small>
        </div>
    </div>

    <div class="card shadow-sm rounded-4 p-4">
        <div class="row gy-3">
            <div class="col-12 col-md-6">
                <label class="form-label">Scan Barcode</label>
                <div class="input-group input-group-lg">
                    <input id="barcodeInput" type="text" class="form-control form-control-lg" placeholder="Place cursor here and scan..." autofocus autocomplete="off">
                    <button id="clearBtn" class="btn btn-outline-secondary">Clear</button>
                </div>
                <div id="loader" class="mt-3 d-none">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Processing...</span></div>
                    <span class="ms-2">Processing barcode...</span>
                </div>
                <div id="alertArea" class="mt-3"></div>
            </div>

            <div class="col-12 col-md-6">
                <div id="reservationCard" class="card rounded-3 d-none">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 id="resId" class="card-title mb-0">Reservation</h5>
                            <span id="statusBadge" class="badge rounded-pill py-2">Status</span>
                        </div>

                        <div class="small text-muted mb-2">Customer</div>
                        <div id="customerName" class="mb-2 fw-semibold">-</div>

                        <div class="small text-muted">Location</div>
                        <div id="location" class="mb-2">-</div>

                        <div class="small text-muted">Scheduled</div>
                        <div id="scheduled" class="mb-2">-</div>

                        <div class="small text-muted">Time In / Out</div>
                        <div id="times" class="mb-0">-</div>
                    </div>
                </div>

                <div id="paymentCard" class="card rounded-3 d-none mt-3">
                    <div class="card-body">
                        <h6 class="mb-3">Payment Summary</h6>
                        <div class="row">
                            <div class="col-6 small text-muted">Duration</div>
                            <div id="duration" class="col-6 text-end">-</div>
                            <div class="col-6 small text-muted">Base Rate</div>
                            <div id="baseRate" class="col-6 text-end">₱0.00</div>
                            <div class="col-6 small text-muted">Overtime</div>
                            <div id="overtime" class="col-6 text-end">₱0.00</div>
                            <div class="col-6 small text-muted">Total</div>
                            <div id="totalAmount" class="col-6 text-end fw-bold">₱0.00</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <footer class="mt-4 text-center text-muted small">SNDRA Park — Booth Module</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="booth.js"></script>
</body>
</html>
