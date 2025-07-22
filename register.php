<?php
session_start();

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: chat.php');
    exit;
}

$fichierUtilisateurs = __DIR__ . '/data/utilisateurs.xml';
$error = '';
$success = '';

// Traitement du formulaire d'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation des champs
    if (empty($username) || empty($password) || empty($confirmPassword)) {
        $error = 'Tous les champs obligatoires doivent être remplis';
    } elseif ($password !== $confirmPassword) {
        $error = 'Les mots de passe ne correspondent pas';
    } elseif (strlen($password) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères';
    } elseif (strlen($username) < 3) {
        $error = 'Le nom d\'utilisateur doit contenir au moins 3 caractères';
    } else {
        // Créer le fichier utilisateurs s'il n'existe pas
        if (!file_exists($fichierUtilisateurs)) {
            if (!is_dir(__DIR__ . '/data')) {
                mkdir(__DIR__ . '/data', 0777, true);
            }
            file_put_contents($fichierUtilisateurs, '<?xml version="1.0" encoding="UTF-8"?><utilisateurs></utilisateurs>');
        }
        
        $xml = simplexml_load_file($fichierUtilisateurs);
        
        // Vérifier si l'utilisateur existe déjà
        $userExists = false;
        
        foreach ($xml->utilisateur as $utilisateur) {
            if ((string)$utilisateur->nom_utilisateur === $username) {
                $userExists = true;
                break;
            }
        }
        
        if ($userExists) {
            $error = 'Ce nom d\'utilisateur existe déjà';
        } else {
            // Créer le nouvel utilisateur
            $utilisateur = $xml->addChild('utilisateur');
            $utilisateur->addChild('id', uniqid());
            $utilisateur->addChild('nom_utilisateur', htmlspecialchars($username));
            $utilisateur->addChild('nom_complet', htmlspecialchars($username)); // Utilise le username comme nom complet
            $utilisateur->addChild('mot_de_passe', password_hash($password, PASSWORD_DEFAULT));
            $utilisateur->addChild('avatar', strtoupper(substr($username, 0, 1)));
            $utilisateur->addChild('statut', 'Nouveau membre');
            $utilisateur->addChild('date_creation', date('Y-m-d H:i:s'));
            $utilisateur->addChild('derniere_connexion', date('Y-m-d H:i:s'));
            $utilisateur->addChild('actif', '1');
            
            if ($xml->asXML($fichierUtilisateurs)) {
                $success = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
                
                // Optionnel : connecter automatiquement l'utilisateur
                $_SESSION['user_id'] = (string)$utilisateur->id;
                $_SESSION['username'] = $username;
                
                // Rediriger vers le chat après 2 secondes
                header('refresh:2;url=chat.php');
            } else {
                $error = 'Erreur lors de la création du compte. Veuillez réessayer.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - ChatApp</title>
    <link rel="stylesheet" href="styles/register.css">
</head>
<body>
    <div class="register-container">
        <div class="logo">💬</div>
        <h1>Créer un compte</h1>
        <p class="subtitle">Rejoignez ChatApp et commencez à discuter</p>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                <?php echo htmlspecialchars($success); ?>
                <div class="loading show">
                    <div class="spinner"></div>
                    Redirection vers le chat...
                </div>
            </div>
        <?php else: ?>

        <form method="POST" id="registerForm">
            <div class="form-group">
                <label for="username">Nom d'utilisateur <span class="required">*</span></label>
                <div class="input-icon user">
                    <input type="text" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           placeholder="Votre nom d'utilisateur">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Mot de passe <span class="required">*</span></label>
                <div class="input-icon password">
                    <input type="password" id="password" name="password" required 
                           placeholder="Minimum 6 caractères">
                </div>
                <div class="password-strength" id="passwordStrength"></div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirmer le mot de passe <span class="required">*</span></label>
                <div class="input-icon password">
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           placeholder="Répétez le mot de passe">
                </div>
            </div>

            <button type="submit" class="btn" id="submitBtn">
                Créer mon compte
            </button>

            <div class="terms">
                En créant un compte, vous acceptez nos 
                <a href="#" onclick="alert('Conditions d\'utilisation')">Conditions d'utilisation</a> 
                et notre 
                <a href="#" onclick="alert('Politique de confidentialité')">Politique de confidentialité</a>.
            </div>
        </form>

        <?php endif; ?>

        <div class="login-link">
            <p>Déjà un compte ? <a href="login.php">Se connecter</a></p>
        </div>
    </div>

    <script>
        // Validation en temps réel du mot de passe
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthDiv.textContent = '';
                return;
            }
            
            let strength = 0;
            let feedback = [];
            
            // Critères de force
            if (password.length >= 8) strength++;
            else feedback.push('Au moins 8 caractères');
            
            if (/[a-z]/.test(password)) strength++;
            else feedback.push('Une minuscule');
            
            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('Une majuscule');
            
            if (/[0-9]/.test(password)) strength++;
            else feedback.push('Un chiffre');
            
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            else feedback.push('Un caractère spécial');
            
            // Affichage de la force
            if (strength < 2) {
                strengthDiv.className = 'password-strength strength-weak';
                strengthDiv.textContent = 'Faible - ' + feedback.slice(0, 2).join(', ');
            } else if (strength < 4) {
                strengthDiv.className = 'password-strength strength-medium';
                strengthDiv.textContent = 'Moyen - ' + feedback.slice(0, 1).join(', ');
            } else {
                strengthDiv.className = 'password-strength strength-strong';
                strengthDiv.textContent = 'Fort - Mot de passe sécurisé';
            }
        });

        // Validation de la confirmation du mot de passe
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.style.borderColor = '#e74c3c';
            } else if (confirmPassword && password === confirmPassword) {
                this.style.borderColor = '#27ae60';
            } else {
                this.style.borderColor = '#e1e5e9';
            }
        });

        // Validation du formulaire avant soumission
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const username = document.getElementById('username').value;
            
            let errors = [];
            
            if (username.length < 3) {
                errors.push('Le nom d\'utilisateur doit contenir au moins 3 caractères');
            }
            
            if (password.length < 6) {
                errors.push('Le mot de passe doit contenir au moins 6 caractères');
            }
            
            if (password !== confirmPassword) {
                errors.push('Les mots de passe ne correspondent pas');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Erreurs de validation:\n' + errors.join('\n'));
                return false;
            }
            
            // Afficher le loading
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div> Création du compte...';
        });
    </script>
</body>
</html>