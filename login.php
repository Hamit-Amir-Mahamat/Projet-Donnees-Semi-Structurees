<?php
// Gestion de la déconnexion en priorité
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_start();
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
session_start();

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: chat.php');
    exit;
}

$fichierUtilisateurs = __DIR__ . '/data/utilisateurs.xml';
$error = '';

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        // Vérifier les identifiants
        if (file_exists($fichierUtilisateurs)) {
            $xml = simplexml_load_file($fichierUtilisateurs);
            foreach ($xml->utilisateur as $utilisateur) {
                // Support pour les anciens mots de passe en texte brut et les nouveaux hashés
                $passwordMatch = false;
                if (isset($utilisateur->mot_de_passe)) {
                    $storedPassword = (string)$utilisateur->mot_de_passe;
                    // Vérifier si c'est un hash ou un mot de passe en texte brut
                    if (password_verify($password, $storedPassword)) {
                        $passwordMatch = true;
                    } elseif ($storedPassword === $password) {
                        // Support pour les anciens comptes avec mots de passe en texte brut
                        $passwordMatch = true;
                    }
                }
                
                if ((string)$utilisateur->nom_utilisateur === $username && $passwordMatch) {
                    $_SESSION['user_id'] = (string)$utilisateur->id;
                    $_SESSION['username'] = $username;
                    
                    // Mettre à jour la dernière connexion
                    $utilisateur->derniere_connexion = date('Y-m-d H:i:s');
                    $xml->asXML($fichierUtilisateurs);
                    
                    header('Location: chat.php');
                    exit;
                }
            }
        }
        $error = 'Nom d\'utilisateur ou mot de passe incorrect';
    } else {
        $error = 'Veuillez remplir tous les champs';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - ChatApp</title>
    <link rel="stylesheet" href="styles/login.css">
</head>
<body>
    <div class="login-container">
        <div class="logo">💬</div>
        <h1>ChatApp</h1>
        <p class="subtitle">Connectez-vous pour commencer à discuter</p>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Formulaire de connexion -->
        <form method="POST" id="loginForm">
            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           placeholder="Entrez votre nom d'utilisateur">
                    <div class="input-icon">👤</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <div class="input-wrapper">
                        <input type="password" id="password" name="password" required 
                           placeholder="Entrez votre mot de passe">
                    <div class="input-icon">🔒</div>
                </div>
            </div>
            
            <button type="submit" class="btn" id="loginBtn">
                Se connecter
            </button>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <span>Connexion en cours...</span>
            </div>
        </form>

        <div class="toggle-form">
            <p>Pas encore de compte ? <a href="register.php">Créer un compte</a></p>
        </div>
    </div>

    <script>
        // Gestion du formulaire
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Veuillez remplir tous les champs');
                return;
            }
            
            // Afficher le loading
            const loginBtn = document.getElementById('loginBtn');
            const loading = document.getElementById('loading');
            
            loginBtn.style.display = 'none';
            loading.classList.add('show');
        });

        // Animation au focus des champs
        const inputs = document.querySelectorAll('input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });

        // Animation d'entrée retardée pour les éléments
        window.addEventListener('load', function() {
            const elements = document.querySelectorAll('.form-group, .toggle-form');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, 200 + (index * 100));
            });
        });
    </script>
</body>
</html>