<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Email - TJSMEDICAL Portal</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .auth-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .verification-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }
        
        .verification-icon.success {
            background: #d4edda;
            color: #155724;
        }
        
        .verification-icon.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .verification-icon.loading {
            background: #e2e3e5;
            color: #6c757d;
        }
        
        h1 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        p {
            color: #666;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .btn-primary {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #e2e3e5;
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .auth-card {
                padding: 30px 20px;
                margin: 10px;
            }
            
            h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div id="loadingState">
                <div class="verification-icon loading">
                    <div class="loading-spinner"></div>
                </div>
                <h1>Verificando...</h1>
                <p>Por favor espera mientras verificamos tu email.</p>
            </div>
            
            <div id="successState" style="display: none;">
                <div class="verification-icon success">✓</div>
                <h1>¡Email Verificado!</h1>
                <p>Tu cuenta ha sido verificada exitosamente. Ya puedes iniciar sesión y acceder a todas las funcionalidades del portal.</p>
                <a href="login.html" class="btn-primary">Iniciar Sesión</a>
            </div>
            
            <div id="errorState" style="display: none;">
                <div class="verification-icon error">✗</div>
                <h1>Error de Verificación</h1>
                <p id="errorMessage">El enlace de verificación es inválido o ha expirado.</p>
                <a href="register.html" class="btn-primary">Registrarse Nuevamente</a>
            </div>
        </div>
    </div>
    
    <script>
        // Verificar email automáticamente al cargar la página
        window.addEventListener('load', async function() {
            const urlParams = new URLSearchParams(window.location.search);
            const token = urlParams.get('token');
            
            if (!token) {
                showError('Token de verificación no encontrado');
                return;
            }
            
            try {
                const response = await fetch(`api/auth/verify-email.php?token=${encodeURIComponent(token)}`);
                const result = await response.json();
                
                if (result.success) {
                    showSuccess();
                } else {
                    showError(result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                showError('Error de conexión. Intenta nuevamente más tarde.');
            }
        });
        
        function showSuccess() {
            document.getElementById('loadingState').style.display = 'none';
            document.getElementById('errorState').style.display = 'none';
            document.getElementById('successState').style.display = 'block';
        }
        
        function showError(message) {
            document.getElementById('loadingState').style.display = 'none';
            document.getElementById('successState').style.display = 'none';
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('errorState').style.display = 'block';
        }
    </script>
</body>
</html>