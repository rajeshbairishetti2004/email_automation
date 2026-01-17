<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quarterly Portfolio Review - Jan-Mar 2026</title>
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Calibri', 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        /* PPT Container - A4 dimensions in pixels for screen */
        .ppt-container {
            width: 1024px;
            height: 768px;
            background: white;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
            border-radius: 8px;
        }

        /* Slide styles */
        .slide {
            width: 100%;
            height: 100%;
            padding: 60px 80px;
            position: absolute;
            top: 0;
            left: 0;
            display: none;
            flex-direction: column;
        }

        .slide.active {
            display: flex;
        }

        /* Common slide elements */
        .slide-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 15px;
            border-bottom: 3px solid #2E75B6;
        }

        .client-name {
            font-size: 32px;
            font-weight: bold;
            color: #2E75B6;
        }

        .review-title {
            font-size: 24px;
            color: #0070C0;
            font-weight: 600;
        }

        .period {
            font-size: 20px;
            color: #555;
            margin-top: 5px;
        }

        .slide-number {
            background: #2E75B6;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
        }

        /* Slide 1 specific styles */
        .main-title {
            text-align: center;
            margin-top: 120px;
        }

        .main-title h1 {
            font-size: 44px;
            color: #2E75B6;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .main-title h2 {
            font-size: 28px;
            color: #0070C0;
            margin-bottom: 30px;
            font-weight: 600;
        }

        .company-logo {
            text-align: center;
            margin-top: 100px;
            color: #555;
            font-size: 18px;
        }

        /* Recommendation boxes */
        .recommendation-box {
            background: #F2F2F2;
            border-left: 6px solid #FFC000;
            padding: 25px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }

        .recommendation-title {
            font-size: 22px;
            color: #2E75B6;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .action-item {
            font-size: 18px;
            margin: 10px 0;
            color: #333;
        }

        .amount {
            color: #C00000;
            font-weight: bold;
        }

        .replace-list, .new-investment-list {
            margin-left: 30px;
            margin-top: 10px;
        }

        .replace-list li, .new-investment-list li {
            margin: 8px 0;
            font-size: 18px;
        }

        /* Impact section */
        .impact-section {
            margin-top: 30px;
        }

        .impact-title {
            font-size: 22px;
            color: #2E75B6;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .impact-points {
            list-style-type: none;
            margin-left: 20px;
        }

        .impact-points li {
            margin: 10px 0;
            font-size: 18px;
            color: #333;
            position: relative;
            padding-left: 25px;
        }

        .impact-points li:before {
            content: "•";
            color: #2E75B6;
            font-size: 24px;
            position: absolute;
            left: 0;
            top: -2px;
        }

        /* Note box */
        .note-box {
            background: #E6F2FF;
            border: 2px solid #2E75B6;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            font-size: 16px;
            color: #333;
        }

        .note-title {
            font-weight: bold;
            color: #2E75B6;
            margin-bottom: 10px;
            font-size: 18px;
        }

        /* Rationale section */
        .rationale-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        .rationale-column {
            font-size: 17px;
            line-height: 1.6;
            color: #333;
        }

        .rationale-column p {
            margin-bottom: 15px;
        }

        .highlight {
            color: #2E75B6;
            font-weight: 600;
        }

        /* Strategic rebalancing */
        .strategic-box {
            background: #FFF2CC;
            border: 2px solid #FFC000;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .strategic-title {
            font-size: 20px;
            color: #C55A11;
            margin-bottom: 15px;
            font-weight: 600;
        }

        /* Contact section */
        .contact-section {
            margin-top: 40px;
        }

        .contact-card {
            background: linear-gradient(135deg, #2E75B6 0%, #1F4E79 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .contact-title {
            font-size: 22px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .contact-name {
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .contact-details {
            font-size: 18px;
            line-height: 1.8;
        }

        .additional-contact {
            text-align: center;
            color: #555;
            font-size: 17px;
        }

        /* Navigation controls */
        .nav-controls {
            position: absolute;
            bottom: 30px;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 30px;
            z-index: 100;
        }

        .nav-btn {
            background: #2E75B6;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }

        .nav-btn:hover {
            background: #1F4E79;
        }

        .nav-btn:disabled {
            background: #95B3D7;
            cursor: not-allowed;
        }

        .slide-counter {
            color: #2E75B6;
            font-size: 16px;
            font-weight: 600;
        }

        /* Export buttons */
        .export-buttons {
            position: absolute;
            top: 30px;
            right: 30px;
            display: flex;
            gap: 10px;
            z-index: 100;
        }

        .export-btn {
            background: white;
            color: #2E75B6;
            border: 2px solid #2E75B6;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .export-btn:hover {
            background: #2E75B6;
            color: white;
        }

        /* Finance Doctor interpretation */
        .interpretation-box {
            background: #F8F8F8;
            border-left: 4px solid #00B050;
            padding: 20px;
            margin: 20px 0;
            font-style: italic;
            color: #555;
        }

        .interpretation-title {
            color: #00B050;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="ppt-container">
        <!-- Export buttons -->
        <div class="export-buttons">
            <button class="export-btn" onclick="downloadPDF()">
                <i class="fas fa-download"></i> Download PDF
            </button>
            <button class="export-btn" onclick="printPPT()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

        <!-- Slide 1: Cover -->
        <div class="slide active" id="slide1">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Quarterly Portfolio Review</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">1</div>
            </div>

            <div class="main-title">
                <h1>Portfolio Review & Recommendations</h1>
                <h2>Quarterly Analysis & Strategy Update</h2>
            </div>

            <div class="company-logo">
                <div style="font-size: 24px; color: #2E75B6; margin-bottom: 10px; font-weight: bold;">
                    Finance Doctor
                </div>
                <div>Wealth Management & Financial Advisory</div>
            </div>
        </div>

        <!-- Slide 2: Recommendations -->
        <div class="slide" id="slide2">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Our Recommendations This Quarter</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">2</div>
            </div>

            <div class="recommendation-box">
                <div class="recommendation-title">Redeem & Replace Strategy</div>
                <div class="action-item">
                    Redeem Quant Flexicap <span class="amount">Rs.10 lakhs</span>
                </div>
                
                <div style="margin-top: 15px; font-weight: 600; color: #2E75B6;">
                    Replace it with:
                </div>
                <ul class="replace-list">
                    <li>Parag Parikh Nasdaq 100 ETF FoF <span class="amount">Rs.4.5 lakhs</span></li>
                    <li>Parag Parikh S&P 500 ETF FoF <span class="amount">Rs.4.5 lakhs</span></li>
                    <li>Motilal Oswal Gold & Silver ETF FoF <span class="amount">Rs.1 lakh</span></li>
                </ul>
            </div>

            <div class="recommendation-box">
                <div class="recommendation-title">New Investment Allocation</div>
                <div class="action-item">
                    New <span class="amount">Rs.10 lakhs</span> investment:
                </div>
                <ul class="new-investment-list">
                    <li>HDFC Multi Asset <span class="amount">Rs.6 lakhs</span></li>
                    <li>SBI Gold Savings ETF <span class="amount">Rs.4 lakhs</span></li>
                </ul>
            </div>

            <div class="impact-section">
                <div class="impact-title">Impact of Our Recommendations</div>
                <ul class="impact-points">
                    <li>Initiation of global wealth allocation</li>
                    <li>Small diversification towards multi assets & precious metals</li>
                </ul>
            </div>
        </div>

        <!-- Slide 3: Rationale -->
        <div class="slide" id="slide3">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Market Rationale & Analysis</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">3</div>
            </div>

            <div class="note-box">
                <div class="note-title">Tax Note:</div>
                Equity LTCG is exempt up to Rs. 1.25 lakhs per financial year; gains above this are taxed at 12.5%. 
                The proposed redemption is within this limit for FY 2025–26, hence no tax is payable if exemption unused elsewhere.
            </div>

            <div class="impact-title">Economic & Market Rationale</div>
            <div class="rationale-grid">
                <div class="rationale-column">
                    <p>India is expected to maintain a healthy GDP growth of <span class="highlight">6.5–7% in 2026</span>.</p>
                    <p>Inflation remains <span class="highlight">well controlled</span>, allowing RBI to <span class="highlight">cut interest rates and inject liquidity</span> into the system.</p>
                    <p>Government measures, including <span class="highlight">tax reforms, GST reductions, and labour reforms</span>, are aimed at supporting consumption.</p>
                    <p>India's <span class="highlight">external balance remains robust</span>, providing macroeconomic stability.</p>
                    <p>Geopolitical uncertainties continue, with recent developments in <span class="highlight">Venezuela</span> and potential unrest in <span class="highlight">Iran</span> adding to global volatility.</p>
                </div>
                <div class="rationale-column">
                    <p>Indian equities are expected to <span class="highlight">perform better in 2026</span> after underperforming in the previous year.</p>
                    <p>The Indian Rupee has <span class="highlight">depreciated by 5%</span>, increasing the relevance of global exposure.</p>
                    <p>In an increasingly uncertain world, alongside <span class="highlight">AI-driven global disruption</span>, initiating <span class="highlight">global wealth creation</span> is prudent.</p>
                    <p>The <span class="highlight">GIFT City route</span> enables USD asset exposure under Indian regulations, without direct exposure to overseas inheritance tax regimes.</p>
                    <p>Given heightened geopolitical risks, <span class="highlight">safe-haven assets</span> merit higher allocation.</p>
                </div>
            </div>
        </div>

        <!-- Slide 4: Finance Doctor's Interpretation -->
        <div class="slide" id="slide4">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Finance Doctor's Interpretation</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">4</div>
            </div>

            <div class="interpretation-box">
                <div class="interpretation-title">Portfolio Strategy:</div>
                To build up global wealth & precious metals. To be moving gradually towards the recommended allocation. 
                As a first step, reduce Indian equity allocation slightly and reinvest in global equity.
            </div>

            <div class="interpretation-box">
                <div class="interpretation-title">Equity MCAP Allocation:</div>
                Different caps have the right percentage ranges. So, no change is recommended.
            </div>

            <div class="interpretation-box">
                <div class="interpretation-title">Equity Sector Allocation:</div>
                Sector allocation remains broadly aligned with long-term objectives.
                Current positioning does not warrant aggressive changes, the focus remains on disciplined rebalancing 
                and maintaining diversification rather than sector rotation.
            </div>

            <div class="interpretation-box">
                <div class="interpretation-title">Global Wealth:</div>
                We are recommending an increase in global allocation and after discussion, it can be implemented gradually.
            </div>

            <div class="interpretation-box">
                <div class="interpretation-title">Performance & Risk Metrics:</div>
                You have earned superior returns with lower volatility — a strong outcome from disciplined portfolio construction.
                These risk measures are based on averages of 4 top portfolio holdings.
            </div>
        </div>

        <!-- Slide 5: Strategic Rebalancing -->
        <div class="slide" id="slide5">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Strategic & Tax-Smart Rebalancing</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">5</div>
            </div>

            <div class="strategic-box">
                <div class="strategic-title">Strategic Rebalancing</div>
                <p style="margin-bottom: 15px; font-size: 17px;">
                    All rebalancing recommendations are optimized in relation to portfolio objectives, risks, market conditions, 
                    and efficient taxation planning.
                </p>
                <p style="font-size: 17px; color: #C00000; font-weight: 600;">
                    We are advocating redemption of Quant Flexicap (Rs. 10.40 lakhs) as the scheme started on a very strong note 
                    in 2021 but has not been able to maintain that momentum over the last 1.5 years.
                </p>
            </div>

            <div class="strategic-box">
                <div class="strategic-title">Tax-Smart Rebalancing</div>
                <p style="margin-bottom: 15px; font-size: 17px;">
                    We aim to minimize the taxation impact while maintaining portfolio objectives by ranking schemes based on tax efficiency.
                </p>
                <p style="font-size: 17px;">
                    This approach allows us to move away from the prescribed FIFO method to a more favourable, engineered LIFO approach 
                    for the same scheme. Wherever feasible, the target recommendation can be achieved gradually across multiple financial 
                    years to reduce tax impact.
                </p>
                <p style="margin-top: 15px; font-size: 17px; color: #00B050; font-weight: 600;">
                    TCS on global allocation can also be avoided by keeping the annual investment amount below Rs. 10 lakhs.
                </p>
            </div>

            <div class="interpretation-box" style="margin-top: 30px;">
                <div class="interpretation-title">Multi-Asset Allocation:</div>
                Multi-asset allocation funds are recommended as they help reduce portfolio risk without compromising return potential.
            </div>
        </div>

        <!-- Slide 6: Contact -->
        <div class="slide" id="slide6">
            <div class="slide-header">
                <div>
                    <div class="client-name">Ms. Mukta Dutta Tomar</div>
                    <div class="review-title">Your Support Team</div>
                    <div class="period">Jan - Mar 2026</div>
                </div>
                <div class="slide-number">6</div>
            </div>

            <div class="contact-section">
                <div class="contact-card">
                    <div class="contact-title">Relationship Manager</div>
                    <div class="contact-name">Sailesh Kumar Mulleti</div>
                    <div class="contact-details">
                        Head of Relationship Team<br>
                        📞 9949700435<br>
                        📧 sailesh.mulleti@financedoctor.in
                    </div>
                </div>

                <div class="additional-contact">
                    <div style="font-weight: 600; color: #2E75B6; margin-bottom: 15px; font-size: 18px;">
                        If required, you may also contact:
                    </div>
                    <div style="font-size: 18px; margin-bottom: 10px;">
                        <strong>Dr. Sanjiv Mehta, MD & Founder</strong>
                    </div>
                    <div style="font-size: 17px; color: #555;">
                        📧 sanjivmehtadr@gmail.com
                    </div>
                </div>

                <div style="text-align: center; margin-top: 50px; color: #777; font-size: 16px;">
                    <div style="font-weight: 600; margin-bottom: 10px;">
                        Quarterly Portfolio Review - Q1 2026
                    </div>
                    <div>
                        Generated on <span id="currentDate"></span> | Confidential & Proprietary
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Controls -->
        <div class="nav-controls">
            <button class="nav-btn" id="prevBtn" onclick="prevSlide()">
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            
            <div class="slide-counter">
                Slide <span id="currentSlide">1</span> of 6
            </div>
            
            <button class="nav-btn" id="nextBtn" onclick="nextSlide()">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <!-- Font Awesome for icons -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    
    <!-- HTML2PDF for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <script>
        // Slide management
        let currentSlide = 1;
        const totalSlides = 6;

        // Set current date
        const currentDate = new Date().toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        document.getElementById('currentDate').textContent = currentDate;

        function showSlide(n) {
            // Hide all slides
            document.querySelectorAll('.slide').forEach(slide => {
                slide.classList.remove('active');
            });
            
            // Show current slide
            document.getElementById(`slide${n}`).classList.add('active');
            
            // Update counter
            document.getElementById('currentSlide').textContent = n;
            
            // Update button states
            document.getElementById('prevBtn').disabled = n === 1;
            document.getElementById('nextBtn').disabled = n === totalSlides;
            
            currentSlide = n;
        }

        function nextSlide() {
            if (currentSlide < totalSlides) {
                showSlide(currentSlide + 1);
            }
        }

        function prevSlide() {
            if (currentSlide > 1) {
                showSlide(currentSlide - 1);
            }
        }

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === ' ') {
                nextSlide();
            } else if (e.key === 'ArrowLeft') {
                prevSlide();
            } else if (e.key === 'Home') {
                showSlide(1);
            } else if (e.key === 'End') {
                showSlide(totalSlides);
            }
        });

        // Initialize
        showSlide(1);

        // PDF Download function
        function downloadPDF() {
            const element = document.querySelector('.ppt-container');
            const options = {
                margin: 0,
                filename: `Portfolio_Review_Ms_Mukta_Dutta_Tomar_Q1_2026.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    scrollY: 0,
                    width: 1024,
                    height: 768
                },
                jsPDF: { 
                    unit: 'px', 
                    format: [1024, 768],
                    orientation: 'portrait' 
                }
            };
            
            // Hide navigation and export buttons during PDF generation
            const navControls = document.querySelector('.nav-controls');
            const exportButtons = document.querySelector('.export-buttons');
            const navDisplay = navControls.style.display;
            const exportDisplay = exportButtons.style.display;
            
            navControls.style.display = 'none';
            exportButtons.style.display = 'none';
            
            html2pdf()
                .from(element)
                .set(options)
                .save()
                .then(() => {
                    // Restore controls
                    navControls.style.display = navDisplay;
                    exportButtons.style.display = exportDisplay;
                });
        }

        // Print function
        function printPPT() {
            const originalBody = document.body.innerHTML;
            const printContent = document.querySelector('.ppt-container').innerHTML;
            
            document.body.innerHTML = `
                <style>
                    @media print {
                        @page { margin: 0; size: landscape; }
                        body { margin: 0; }
                    }
                    .ppt-container {
                        width: 100vw;
                        height: 100vh;
                        background: white;
                    }
                    .slide {
                        display: flex !important;
                        page-break-after: always;
                    }
                    .nav-controls, .export-buttons {
                        display: none !important;
                    }
                </style>
                <div class="ppt-container">${printContent}</div>
            `;
            
            window.print();
            document.body.innerHTML = originalBody;
            showSlide(currentSlide); // Restore current slide
        }

        // Initialize slide navigation
        document.getElementById('prevBtn').disabled = true;
    </script>
</body>
</html>