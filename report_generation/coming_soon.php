<?php
// coming_soon.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coming Soon</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, #f5f9fc, #e3f2fd);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .coming-wrapper {
            background: #ffffff;
            padding: 60px 50px;
            border-radius: 18px;
            box-shadow: 0 20px 40px rgba(2, 136, 209, 0.12);
            text-align: center;
            max-width: 520px;
            width: 90%;
            position: relative;
            overflow: hidden;
        }

        /* subtle top accent line */
        .coming-wrapper::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, #0288D1, #26c6da);
        }

        .icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e3f2fd;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px auto;
            font-size: 34px;
            color: #0288D1;
        }

        h1 {
            font-size: 32px;
            color: #0288D1;
            margin-bottom: 15px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        p {
            font-size: 16px;
            color: #555;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            background: #e1f5fe;
            color: #0288D1;
            font-size: 13px;
            font-weight: 500;
            border-radius: 20px;
        }

        /* subtle floating animation */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-6px); }
            100% { transform: translateY(0px); }
        }

        .coming-wrapper {
            animation: float 6s ease-in-out infinite;
        }

        @media (max-width: 600px) {
            .coming-wrapper {
                padding: 40px 25px;
            }

            h1 {
                font-size: 24px;
            }

            p {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

    <div class="coming-wrapper">
        <div class="icon-circle">
            🚀
        </div>

        <h1>Coming Soon</h1>

        <p>
            We're working on something exciting.  
            This feature will be available shortly.
        </p>

        <div class="status-badge">
            Under Development
        </div>
    </div>

</body>
</html>
