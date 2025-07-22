<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Chemins vers les fichiers XML
$dossierAvatars = __DIR__ . '/data/avatars/';
if (!is_dir($dossierAvatars)) {
    mkdir($dossierAvatars, 0777, true);
}
$fichierMessages = __DIR__ . '/data/messages.xml';
$fichierUtilisateurs = __DIR__ . '/data/utilisateurs.xml';
$fichierStatuts = __DIR__ . '/data/statuts.xml';
$fichierGroupes = __DIR__ . '/data/groupes.xml';

// Créer les fichiers s'ils n'existent pas
if (!file_exists($fichierMessages)) {
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0777, true);
    }
    file_put_contents($fichierMessages, '<?xml version="1.0" encoding="UTF-8"?><messages></messages>');
}

if (!file_exists($fichierStatuts)) {
    file_put_contents($fichierStatuts, '<?xml version="1.0" encoding="UTF-8"?><statuts></statuts>');
}

if (!file_exists($fichierGroupes)) {
    file_put_contents($fichierGroupes, '<?xml version="1.0" encoding="UTF-8"?><groupes></groupes>');
}

// Mettre à jour le statut de l'utilisateur (en ligne)
function updateUserStatus($userId, $status = 'online') {
    global $fichierStatuts;
    
    $xml = simplexml_load_file($fichierStatuts);
    $userFound = false;
    
    foreach ($xml->statut as $statut) {
        if ((string)$statut->user_id === $userId) {
            $statut->status = $status;
            $statut->last_seen = date('Y-m-d H:i:s');
            $userFound = true;
            break;
        }
    }
    
    if (!$userFound) {
        $statut = $xml->addChild('statut');
        $statut->addChild('user_id', $userId);
        $statut->addChild('status', $status);
        $statut->addChild('last_seen', date('Y-m-d H:i:s'));
    }
    
    $xml->asXML($fichierStatuts);
}

// Mettre à jour le statut de l'utilisateur actuel
updateUserStatus($_SESSION['user_id'], 'online');

// API pour créer un groupe
// API pour mettre à jour le profil utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    header('Content-Type: application/json');
    $userId = $_SESSION['user_id'];
    $nom = trim($_POST['nom_utilisateur'] ?? '');
    $avatarPath = null;
    $deleteAvatar = isset($_POST['delete_avatar']) ? true : false;
    $descriptions = isset($_POST['descriptions']) ? json_decode($_POST['descriptions'], true) : [];
    $description_active = isset($_POST['description_active']) ? trim($_POST['description_active']) : '';

    // Gestion de l'upload de l'avatar (max 2Mo)
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['avatar']['tmp_name'];
        $fileName = basename($_FILES['avatar']['name']);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed) && $_FILES['avatar']['size'] <= 2*1024*1024) {
            $newName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $destPath = $dossierAvatars . $newName;
            if (move_uploaded_file($fileTmp, $destPath)) {
                $avatarPath = 'data/avatars/' . $newName;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Format ou taille de l\'image invalide']);
            exit;
        }
    }

    if (file_exists($fichierUtilisateurs)) {
        $xml = simplexml_load_file($fichierUtilisateurs);
        foreach ($xml->utilisateur as $utilisateur) {
            if ((string)$utilisateur->id === $userId) {
                if ($nom) $utilisateur->nom_utilisateur = $nom;
                // Hachage du mot de passe si fourni
                if (isset($_POST['mot_de_passe']) && $_POST['mot_de_passe'] !== '') {
                    $utilisateur->mot_de_passe = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);
                }
                // Gérer l'avatar
                if ($deleteAvatar) {
                    if (isset($utilisateur->avatar) && file_exists(__DIR__ . '/' . (string)$utilisateur->avatar)) {
                        unlink(__DIR__ . '/' . (string)$utilisateur->avatar);
                    }
                    unset($utilisateur->avatar);
                } elseif ($avatarPath) {
                    $utilisateur->avatar = $avatarPath;
                }

                // Gérer les descriptions multiples UNIQUEMENT si une nouvelle liste est soumise
                if (is_array($descriptions) && count($descriptions) > 0) {
                    unset($utilisateur->descriptions);
                    $descsNode = $utilisateur->addChild('descriptions');
                    foreach ($descriptions as $desc) {
                        $descsNode->addChild('description', htmlspecialchars($desc));
                    }
                }

                // Description active : ne pas écraser si vide
                if ($description_active !== '') {
                    $utilisateur->description_active = $description_active;
                }

                $xml->asXML($fichierUtilisateurs);
                echo json_encode(['success' => true]);
                exit;
            }
        }
    }
    echo json_encode(['success' => false, 'error' => 'Utilisateur non trouvé']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_group') {
    header('Content-Type: application/json');
    
    $groupName = trim($_POST['group_name'] ?? '');
    $groupDescription = trim($_POST['group_description'] ?? '');
    $members = json_decode($_POST['members'] ?? '[]', true);
    
    if ($groupName && !empty($members)) {
        $xml = simplexml_load_file($fichierGroupes);
        
        $groupId = uniqid('group_');
        $group = $xml->addChild('groupe');
        $group->addChild('id', $groupId);
        $group->addChild('nom', htmlspecialchars($groupName));
        $group->addChild('description', htmlspecialchars($groupDescription));
        $group->addChild('createur_id', $_SESSION['user_id']);
        $group->addChild('date_creation', date('Y-m-d H:i:s'));
        $group->addChild('avatar', strtoupper(substr($groupName, 0, 1)));
        
        // Ajouter les membres
        $membresNode = $group->addChild('membres');
        
        // Ajouter le créateur comme membre
        $membre = $membresNode->addChild('membre');
        $membre->addChild('user_id', $_SESSION['user_id']);
        $membre->addChild('role', 'admin');
        $membre->addChild('date_ajout', date('Y-m-d H:i:s'));
        
        // Ajouter les autres membres
        foreach ($members as $memberId) {
            if ($memberId !== $_SESSION['user_id']) {
                $membre = $membresNode->addChild('membre');
                $membre->addChild('user_id', $memberId);
                $membre->addChild('role', 'membre');
                $membre->addChild('date_ajout', date('Y-m-d H:i:s'));
            }
        }
        
        if ($xml->asXML($fichierGroupes)) {
            echo json_encode([
                'success' => true,
                'group' => [
                    'id' => $groupId,
                    'name' => $groupName,
                    'description' => $groupDescription,
                    'avatar' => strtoupper(substr($groupName, 0, 1)),
                    'type' => 'group'
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la création du groupe']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Nom du groupe et membres requis']);
    }
    exit;
}
// Charger l'utilisateur actuel avec description et avatar
$utilisateurActuel = null;
if (file_exists($fichierUtilisateurs)) {
    $xml = simplexml_load_file($fichierUtilisateurs);
    foreach ($xml->utilisateur as $utilisateur) {
        if ((string)$utilisateur->id === $_SESSION['user_id']) {
            $desc = '';
            if (isset($utilisateur->description_active) && trim((string)$utilisateur->description_active) !== '') {
                $desc = (string)$utilisateur->description_active;
            } elseif (isset($utilisateur->descriptions->description[0])) {
                $desc = (string)$utilisateur->descriptions->description[0];
            }
            $utilisateurActuel = [
                'id' => (string)$utilisateur->id,
                'nom_utilisateur' => (string)$utilisateur->nom_utilisateur,
                'avatar' => isset($utilisateur->avatar) && file_exists(__DIR__ . '/' . (string)$utilisateur->avatar) ? (string)$utilisateur->avatar : null,
                'initial' => strtoupper(substr((string)$utilisateur->nom_utilisateur, 0, 1)),
                'initial_style' => "background-color: " . sprintf('#%06X', rand(0, 0xFFFFFF)) . "; color: white;",
                'description' => $desc
            ];
            break;
        }
    }
}

// API pour envoyer un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    header('Content-Type: application/json');
    
    $recipient = $_POST['recipient'] ?? '';
    $message = $_POST['message'] ?? '';
    $isGroup = isset($_POST['is_group']) && $_POST['is_group'] === 'true';
    
    $fileUrl = '';
    // Gestion de l'upload de fichier
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/data/files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = basename($_FILES['file']['name']);
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $uniqueName = 'file_' . uniqid() . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $uniqueName;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            $fileUrl = 'data/files/' . $uniqueName;
        }
    }

    if ($recipient && ($message || $fileUrl)) {
        $xml = simplexml_load_file($fichierMessages);
        $msgId = uniqid();
        $msgTimestamp = date('Y-m-d H:i:s');
        $messageNode = $xml->addChild('message');
        $messageNode->addChild('id', $msgId);
        $messageNode->addChild('sender_id', $_SESSION['user_id']);
        $messageNode->addChild('recipient_id', $recipient);
        // Construction du contenu du message
        $msgContent = $message;
        if ($fileUrl) {
            $originalName = isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '';
            $fileLink = '<a href="' . $fileUrl . '" target="_blank" data-original-name="' . htmlspecialchars($originalName) . '">📎 Fichier</a>';
            if ($msgContent) {
                $msgContent .= '<br>' . $fileLink;
            } else {
                $msgContent = $fileLink;
            }
        }
        $messageNode->addChild('content', htmlspecialchars($msgContent));
        $messageNode->addChild('timestamp', $msgTimestamp);
        $messageNode->addChild('status', 'sent');
        $messageNode->addChild('is_group', $isGroup ? '1' : '0');

        $xml->asXML($fichierMessages);

        echo json_encode([
            'success' => true,
            'message' => [
                'id' => $msgId,
                'sender' => $_SESSION['user_id'],
                'recipient' => $recipient,
                'content' => $msgContent,
                'timestamp' => $msgTimestamp,
                'type' => $fileUrl ? 'file' : 'text',
                'is_group' => $isGroup,
                'status' => 'sent',
                'file' => $fileUrl
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Données manquantes']);
    }
    exit;
}

// API pour récupérer les messages
if (isset($_GET['action']) && $_GET['action'] === 'get_messages' && isset($_GET['contact_id'])) {
    header('Content-Type: application/json');
    $contactId = $_GET['contact_id'];
    $isGroup = isset($_GET['is_group']) && $_GET['is_group'] === 'true';
    $lastMessageId = $_GET['last_message_id'] ?? null;
    $messagesList = [];

    if (file_exists($fichierMessages)) {
        $xml = simplexml_load_file($fichierMessages);
        foreach ($xml->message as $msg) {
            $msgIsGroup = isset($msg->is_group) && (string)$msg->is_group === '1';

            // Mise à jour du statut pour les messages privés
            if (!$isGroup && !$msgIsGroup) {
                if ((string)$msg->recipient_id === $_SESSION['user_id'] && isset($msg->status) && (string)$msg->status === 'sent') {
                    $msg->status = 'delivered';
                }
                if ((string)$msg->recipient_id === $_SESSION['user_id'] && isset($msg->status) && (string)$msg->status === 'delivered') {
                    $msg->status = 'read';
                }
            }

            // Détection du type de message (fichier ou texte)
            $msgType = 'text';
            if (strpos((string)$msg->content, '<a href=') !== false && strpos((string)$msg->content, '📎 Fichier') !== false) {
                $msgType = 'file';
            }

            if ($isGroup && $msgIsGroup && (string)$msg->recipient_id === $contactId) {
                $messageData = [
                    'id' => (string)$msg->id,
                    'sender' => (string)$msg->sender_id,
                    'recipient' => (string)$msg->recipient_id,
                    'content' => (string)$msg->content,
                    'timestamp' => (string)$msg->timestamp,
                    'type' => $msgType,
                    'is_group' => true,
                    'status' => isset($msg->status) ? (string)$msg->status : 'sent'
                ];
                if ($lastMessageId && (string)$msg->id === $lastMessageId) {
                    $messagesList = [];
                    continue;
                }
                $messagesList[] = $messageData;
            } elseif (!$isGroup && !$msgIsGroup) {
                if (
                    ((string)$msg->sender_id === $_SESSION['user_id'] && (string)$msg->recipient_id === $contactId) ||
                    ((string)$msg->sender_id === $contactId && (string)$msg->recipient_id === $_SESSION['user_id'])
                ) {
                    $messageData = [
                        'id' => (string)$msg->id,
                        'sender' => (string)$msg->sender_id,
                        'recipient' => (string)$msg->recipient_id,
                        'content' => (string)$msg->content,
                        'timestamp' => (string)$msg->timestamp,
                        'type' => $msgType,
                        'is_group' => false,
                        'status' => isset($msg->status) ? (string)$msg->status : 'sent'
                    ];
                    if ($lastMessageId && (string)$msg->id === $lastMessageId) {
                        $messagesList = [];
                        continue;
                    }
                    $messagesList[] = $messageData;
                }
            }
        }
        $xml->asXML($fichierMessages);
    }
    
    // Tri par date
    usort($messagesList, function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
    
    echo json_encode($messagesList);
    exit;
}

// API pour récupérer les nouveaux messages
if (isset($_GET['action']) && $_GET['action'] === 'get_new_messages') {
    header('Content-Type: application/json');
    $lastCheck = $_GET['last_check'] ?? date('Y-m-d H:i:s', time() - 60);
    $newMessages = [];

    if (file_exists($fichierMessages)) {
        $xml = simplexml_load_file($fichierMessages);
        foreach ($xml->message as $msg) {
            $msgIsGroup = isset($msg->is_group) && (string)$msg->is_group === '1';
            
            if (strtotime((string)$msg->timestamp) > strtotime($lastCheck)) {
                // Messages privés reçus
                if (!$msgIsGroup && (string)$msg->recipient_id === $_SESSION['user_id']) {
                    $newMessages[] = [
                        'id' => (string)$msg->id,
                        'sender' => (string)$msg->sender_id,
                        'recipient' => (string)$msg->recipient_id,
                        'content' => (string)$msg->content,
                        'timestamp' => (string)$msg->timestamp,
                        'type' => 'text',
                        'is_group' => false
                    ];
                }
                // Messages de groupe où l'utilisateur est membre
                elseif ($msgIsGroup && (string)$msg->sender_id !== $_SESSION['user_id']) {
                    // Vérifier si l'utilisateur est membre du groupe
                    $groupId = (string)$msg->recipient_id;
                    if (isUserInGroup($_SESSION['user_id'], $groupId)) {
                        $newMessages[] = [
                            'id' => (string)$msg->id,
                            'sender' => (string)$msg->sender_id,
                            'recipient' => $groupId,
                            'content' => (string)$msg->content,
                            'timestamp' => (string)$msg->timestamp,
                            'type' => 'text',
                            'is_group' => true
                        ];
                    }
                }
            }
        }
    }
    
    echo json_encode($newMessages);
    exit;
}

// API pour récupérer les statuts des utilisateurs
if (isset($_GET['action']) && $_GET['action'] === 'get_user_statuses') {
    header('Content-Type: application/json');
    $statuses = [];
    
    if (file_exists($fichierStatuts)) {
        $xml = simplexml_load_file($fichierStatuts);
        foreach ($xml->statut as $statut) {
            $lastSeen = strtotime((string)$statut->last_seen);
            $isOnline = (time() - $lastSeen) < 300; // 5 minutes
            
            $statuses[(string)$statut->user_id] = [
                'status' => $isOnline ? 'online' : 'offline',
                'last_seen' => (string)$statut->last_seen
            ];
        }
    }
    
    echo json_encode($statuses);
    exit;
}

// Fonction pour vérifier si un utilisateur est membre d'un groupe
function isUserInGroup($userId, $groupId) {
    global $fichierGroupes;
    
    if (!file_exists($fichierGroupes)) {
        return false;
    }
    
    $xml = simplexml_load_file($fichierGroupes);
    foreach ($xml->groupe as $groupe) {
        if ((string)$groupe->id === $groupId) {
            foreach ($groupe->membres->membre as $membre) {
                if ((string)$membre->user_id === $userId) {
                    return true;
                }
            }
        }
    }
    
    return false;
}

// Charger les utilisateurs depuis XML
$utilisateurs = [];
if (file_exists($fichierUtilisateurs)) {
    $xml = simplexml_load_file($fichierUtilisateurs);
    foreach ($xml->utilisateur as $utilisateur) {
        if ((string)$utilisateur->id !== $_SESSION['user_id']) {
            $desc = '';
            if (isset($utilisateur->description_active) && trim((string)$utilisateur->description_active) !== '') {
                $desc = (string)$utilisateur->description_active;
            } elseif (isset($utilisateur->descriptions->description[0])) {
                $desc = (string)$utilisateur->descriptions->description[0];
            }
            $utilisateurs[] = [
                'id' => (string)$utilisateur->id,
                'nom_utilisateur' => (string)$utilisateur->nom_utilisateur,
                'avatar' => isset($utilisateur->avatar) && file_exists(__DIR__ . '/' . (string)$utilisateur->avatar) ? (string)$utilisateur->avatar : null,
                'initial' => strtoupper(substr((string)$utilisateur->nom_utilisateur, 0, 1)),
                'initial_style' => "background-color: " . sprintf('#%06X', rand(0, 0xFFFFFF)) . "; color: white;",
                'description' => $desc
            ];
        }
    }
}

// Charger les groupes depuis XML
$groupes = [];
if (file_exists($fichierGroupes)) {
    $xml = simplexml_load_file($fichierGroupes);
    foreach ($xml->groupe as $groupe) {
        // Vérifier si l'utilisateur actuel est membre du groupe
        $isMember = false;
        foreach ($groupe->membres->membre as $membre) {
            if ((string)$membre->user_id === $_SESSION['user_id']) {
                $isMember = true;
                break;
            }
        }
        
        if ($isMember) {
            $groupes[] = [
                'id' => (string)$groupe->id,
                'nom' => (string)$groupe->nom,
                'description' => (string)$groupe->description,
                'avatar' => (string)$groupe->avatar,
                'createur_id' => (string)$groupe->createur_id,
                'type' => 'group'
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChatApp</title>
    <link rel="stylesheet" href="styles/chat.css">
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="user-info" style="flex-direction:column;align-items:flex-start;width:100%;">
                    <div style="width:100%;display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="avatar-container">
                                <?php if (isset($utilisateurActuel['avatar']) && file_exists(__DIR__ . '/' . $utilisateurActuel['avatar'])): ?>
                                    <img class="avatar" id="userAvatar" src="<?php echo $utilisateurActuel['avatar']; ?>" alt="Avatar" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <div class="avatar" style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;<?php echo $utilisateurActuel['initial_style']; ?>"><?php echo $utilisateurActuel['initial']; ?></div>
                                <?php endif; ?>
                                <div class="online-status"></div>
                            </div>
                            <div>
                                <div id="userName" style="font-weight:bold;font-size:16px;">
                                    <?php echo htmlspecialchars($utilisateurActuel['nom_utilisateur'] ?? 'Utilisateur'); ?>
                                </div>
                                <div id="userDescription" style="font-size: 12px; opacity: 0.8;">
                                    <?php echo htmlspecialchars($utilisateurActuel['description'] ?? ''); ?>
                                </div>
                            </div>
                        </div>
                        <div class="menu-buttons" style="display:flex;gap:8px;">
                            <button class="menu-btn" onclick="openProfileModal()" title="Mon profil">👤</button>
                            <button class="theme-toggle" onclick="toggleTheme()" id="themeToggle" title="Changer le thème">🌙</button>
                            <button class="create-group-btn" onclick="openCreateGroupModal()" title="Créer un groupe">👥</button>
                            <button class="menu-btn" onclick="logout()" title="Déconnexion">🚪</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="search-bar">
                <input type="text" class="search-input" placeholder="Rechercher une conversation..." id="searchInput">
            </div>

            <div class="tabs">
                <button class="tab active" onclick="switchTab('chats')" id="chatsTab">Discussions</button>
                <button class="tab" onclick="switchTab('groups')" id="groupsTab">Groupes</button>
                <button class="tab" onclick="switchTab('unread')" id="unreadTab">Non lus</button>
                <button class="tab" onclick="switchTab('contacts')" id="contactsTab">Contacts</button>
            </div>

            <div class="contacts-list" id="contactsList">
                <!-- Les contacts seront ajoutés dynamiquement -->
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="chat-header" id="chatHeader" style="display: none;">
                <div class="chat-info">
                    <div class="avatar-container">
                        <div class="avatar" id="contactAvatar">C</div>
                        <div class="online-status" id="contactStatus"></div>
                    </div>
                    <div>
                        <div id="contactName">Contact</div>
                        <div id="contactStatusText" style="font-size: 12px; opacity: 0.8;">En ligne</div>
                    </div>
                </div>
                <div class="chat-actions">
                    <button class="menu-btn" onclick="clearChat()" title="Effacer la conversation">🗑️</button>
                </div>
            </div>

            <div class="messages-container" id="messagesContainer">
                <div class="empty-chat">
                    <h3>Bienvenue sur ChatApp</h3>
                    <p>Sélectionnez une conversation pour commencer à discuter</p>
                </div>
            </div>

            <div class="message-input-container" id="messageInputContainer" style="display: none;">
                <input type="text" class="message-input" placeholder="Tapez votre message..." id="messageInput">
                <input type="file" id="fileInput" style="display:none;" />
                <div id="filePreview" style="display:none;align-items:center;gap:8px;margin:8px 0 0 0;"></div>
                <button class="file-btn" onclick="document.getElementById('fileInput').click();" title="Envoyer un fichier" id="fileBtn">📎</button>
                <button class="send-btn" onclick="sendMessage()" title="Envoyer" id="sendBtn">➤</button>
            </div>
        </div>
    </div>

    <!-- Modal de création de groupe -->
    <div class="modal" id="createGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Créer un nouveau groupe</h2>
                <button class="close-btn" onclick="closeCreateGroupModal()">×</button>
            </div>
            
            <form id="createGroupForm">
                <div class="form-group">
                    <label class="form-label" for="groupName">Nom du groupe *</label>
                    <input type="text" class="form-input" id="groupName" placeholder="Entrez le nom du groupe" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="groupDescription">Description (optionnel)</label>
                    <textarea class="form-input form-textarea" id="groupDescription" placeholder="Décrivez le groupe..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Sélectionner les membres</label>
                    <div class="members-selection" id="membersSelection">
                        <!-- Les membres seront ajoutés dynamiquement -->
                    </div>
                </div>
                
                <button type="submit" class="btn-primary" id="createGroupBtn">
                    Créer le groupe
                </button>
            </form>
        </div>
    </div>


    <!-- Modal de gestion de profil -->
    <div class="modal" id="profileModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Mon profil</h2>
                <button class="close-btn" onclick="closeProfileModal()">×</button>
            </div>
            <form id="profileForm" enctype="multipart/form-data">
                <div class="form-group" style="text-align:center;">
                    <label for="profileAvatar" class="form-label">Photo de profil</label><br>
                    <img id="profileAvatarPreview" src="<?php echo (isset($utilisateurActuel['avatar']) && file_exists(__DIR__ . '/' . $utilisateurActuel['avatar'])) ? $utilisateurActuel['avatar'] : 'data/avatars/default_avatar.jpg'; ?>" alt="Avatar" style="width:100px;height:100px;border-radius:50%;object-fit:cover;margin-bottom:10px;box-shadow:0 2px 8px #aaa;">
                    <input type="file" id="profileAvatar" name="avatar" accept="image/*" style="margin-top:10px;">
                    <?php if (isset($utilisateurActuel['avatar']) && $utilisateurActuel['avatar'] !== 'data/avatars/default_avatar.jpg'): ?>
                    <div style="margin-top:10px;">
                        <label style="cursor:pointer;color:#ff6b6b;font-size:13px;"><input type="checkbox" name="delete_avatar" id="deleteAvatar"> Supprimer la photo</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="profileName">Nom d'utilisateur</label>
                    <input type="text" class="form-input" id="profileName" name="nom_utilisateur" value="<?php echo htmlspecialchars($utilisateurActuel['nom_utilisateur'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Descriptions</label>
                    <div id="descriptionsList">
                        <?php if (isset($utilisateurActuel['descriptions']) && is_array($utilisateurActuel['descriptions'])): ?>
                            <?php foreach ($utilisateurActuel['descriptions'] as $desc): ?>
                                <div class="desc-item" style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                    <input type="radio" name="description_active" value="<?php echo htmlspecialchars($desc); ?>" <?php echo ($desc == $utilisateurActuel['description_active']) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($desc); ?></span>
                                    <button type="button" class="menu-btn" onclick="removeDescription(this)">🗑️</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <input type="text" class="form-input" id="newDescriptionInput" placeholder="Ajouter une nouvelle description...">
                    <button type="button" class="btn-primary" style="margin-top:8px;" onclick="addDescription()">Ajouter</button>
                </div>
                <button type="submit" class="btn-primary" id="saveProfileBtn">Sauvegarder</button>
                <button type="button" class="btn-primary" style="background:linear-gradient(135deg,#ff6b6b 0%,#feca57 100%);margin-top:10px;" onclick="logout()">Déconnexion</button>
            </form>
        </div>
    </div>

    <button class="create-group-btn" onclick="openCreateGroupModal()" title="Créer un groupe">👥</button>
    <button class="theme-toggle" onclick="toggleTheme()" id="themeToggle">🌙</button>
    <script>
        // Variables globales
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        let currentUser = {
            id: "<?php echo $utilisateurActuel['id'] ?? '1'; ?>",
            name: "<?php echo htmlspecialchars($utilisateurActuel['nom_utilisateur'] ?? 'Utilisateur'); ?>",
            avatar: "<?php echo (isset($utilisateurActuel['avatar']) && file_exists(__DIR__ . '/' . $utilisateurActuel['avatar'])) ? $utilisateurActuel['avatar'] : ''; ?>",
            initial: "<?php echo $utilisateurActuel['initial']; ?>",
            initial_style: "<?php echo $utilisateurActuel['initial_style']; ?>"
        };

        let contacts = [
            <?php foreach ($utilisateurs as $utilisateur): ?>
            {
                id: "<?php echo $utilisateur['id']; ?>",
                name: "<?php echo htmlspecialchars($utilisateur['nom_utilisateur']); ?>",
                avatar: "<?php echo (isset($utilisateur['avatar']) && file_exists(__DIR__ . '/' . $utilisateur['avatar'])) ? $utilisateur['avatar'] : ''; ?>",
                initial: "<?php echo $utilisateur['initial']; ?>",
                initial_style: "<?php echo $utilisateur['initial_style']; ?>",
                status: "offline",
                lastMessage: "",
                lastMessageTime: "",
                isOnline: false,
                type: "user"
            },
            <?php endforeach; ?>
        ];

        let groups = [
            <?php foreach ($groupes as $groupe): ?>
            {
                id: "<?php echo $groupe['id']; ?>",
                name: "<?php echo htmlspecialchars($groupe['nom']); ?>",
                description: "<?php echo htmlspecialchars($groupe['description']); ?>",
                avatar: "<?php echo htmlspecialchars($groupe['avatar']); ?>",
                createur_id: "<?php echo $groupe['createur_id']; ?>",
                lastMessage: "",
                lastMessageTime: "",
                type: "group"
            },
            <?php endforeach; ?>
        ];

        let currentChat = null;
        let currentTab = 'chats';
        let lastMessageCheck = new Date().toISOString();
        let pollingInterval = null;
        let userStatuses = {};
        // Gestion de la modale de profil
        function openProfileModal() {
            document.getElementById('profileModal').classList.add('show');
        }
        function closeProfileModal() {
            document.getElementById('profileModal').classList.remove('show');
            document.getElementById('profileForm').reset();
            // Réinitialiser la liste des descriptions
            document.getElementById('descriptionsList').innerHTML = '';
        }

        // Ajout d'une description
        function addDescription() {
            const input = document.getElementById('newDescriptionInput');
            const value = input.value.trim();
            if (!value) return;
            const list = document.getElementById('descriptionsList');
            // Créer l'élément
            const div = document.createElement('div');
            div.className = 'desc-item';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.gap = '8px';
            div.style.marginBottom = '5px';
            div.innerHTML = `<input type="radio" name="description_active" value="${value}"><input type="text" class="desc-edit" value="${value}" style="flex:1;border:none;background:transparent;font-size:14px;"> <button type="button" class="menu-btn" onclick="removeDescription(this)">🗑️</button>`;
            list.appendChild(div);
            input.value = '';
        }

        // Modification d'une description (inline)
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('desc-edit')) {
                // Mettre à jour la valeur du radio associé
                const radio = e.target.parentElement.querySelector('input[type="radio"]');
                radio.value = e.target.value;
            }
        });

        // Suppression d'une description
        function removeDescription(btn) {
            const div = btn.parentElement;
            div.remove();
        }

        // À l'envoi du formulaire, récupérer toutes les descriptions et la description active
        document.getElementById('profileForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Récupérer les descriptions
            const descs = [];
            document.querySelectorAll('#descriptionsList .desc-item .desc-edit').forEach(input => {
                descs.push(input.value);
            });
            formData.append('descriptions', JSON.stringify(descs));
            // Récupérer la description active
            const active = document.querySelector('input[name="description_active"]:checked');
            formData.append('description_active', active ? active.value : '');
            formData.append('action', 'update_profile');
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Profil mis à jour !', 'success');
                    closeProfileModal();
                    location.reload();
                } else {
                    showNotification('Erreur lors de la mise à jour du profil', 'error');
                }
            })
            .catch(() => {
                showNotification('Erreur lors de la mise à jour du profil', 'error');
            });
        });

        // Preview du fichier à envoyer dans la barre de message
        fileInput?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                filePreview.style.display = 'flex';
                filePreview.innerHTML = '';
                const ext = file.name.split('.').pop().toLowerCase();
                let icon = '';
                if (["jpg","jpeg","png","gif","bmp","webp"].includes(ext)) {
                    // Aperçu image
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        filePreview.innerHTML = `<img src='${ev.target.result}' alt='Image' style='max-width:60px;max-height:60px;border-radius:8px;'> <button type='button' style='background:none;border:none;font-size:38px;color:#ef4444;cursor:pointer;' onclick='removeFilePreview()'>✖</button>`;
                    };
                    reader.readAsDataURL(file);
                    return;
                }
                // Icônes selon le type de fichier
                if (["doc","docx"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#2b579a;'>📄</span>`; // Word (bleu)
                } else if (["xls","xlsx"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#217346;'>📊</span>`; // Excel (vert)
                } else if (["ppt","pptx"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#d24726;'>📈</span>`; // PowerPoint (orange)
                } else if (["pdf"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#e53935;'>📕</span>`; // PDF (rouge)
                } else if (["txt"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#333;'>📄</span>`; // TXT (gris)
                } else if (["zip","rar","7z"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#f9a825;'>🗜️</span>`; // Archive (jaune)
                } else if (["mp3","wav","ogg"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#6d4c41;'>🎵</span>`; // Audio (marron)
                } else if (["mp4","avi","mov","wmv","mkv"].includes(ext)) {
                    icon = `<span style='font-size:28px;color:#0288d1;'>🎬</span>`; // Vidéo (bleu)
                } else {
                    icon = `<span style='font-size:28px;color:#666;'>�</span>`; // Autre (gris)
                }
                filePreview.innerHTML = `${icon} <span style='font-size:13px;'>${file.name}</span> <button type='button' style='background:none;border:none;font-size:38px;color:#ef4444;cursor:pointer;' onclick='removeFilePreview()'>✖</button>`;
            } else {
                filePreview.style.display = 'none';
                filePreview.innerHTML = '';
            }
        });

        // Fonction pour retirer le fichier sélectionné
        function removeFilePreview() {
            fileInput.value = '';
            filePreview.style.display = 'none';
            filePreview.innerHTML = '';
        }

        // Sauvegarde du profil
        document.getElementById('profileForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_profile');
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Profil mis à jour !', 'success');
                    closeProfileModal();
                    location.reload();
                } else {
                    showNotification('Erreur lors de la mise à jour du profil', 'error');
                }
            })
            .catch(() => {
                showNotification('Erreur lors de la mise à jour du profil', 'error');
            });
        });

        // Déconnexion
        function logout() {
            fetch('login.php?action=logout', {method:'POST'})
                .then(() => { window.location.href = 'login.php'; });
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            loadContacts();
            startPolling();
            
            // Event listeners
            document.getElementById('messageInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });

            document.getElementById('searchInput').addEventListener('input', function() {
                searchContacts(this.value);
            });

            document.getElementById('createGroupForm').addEventListener('submit', function(e) {
                e.preventDefault();
                createGroup();
            });

            // Mettre à jour le statut de l'utilisateur toutes les 2 minutes
            setInterval(updateUserStatus, 120000);
            
            // Vérifier les statuts des utilisateurs
            setInterval(checkUserStatuses, 30000);
            checkUserStatuses();
        });

        function loadContacts() {
            const contactsList = document.getElementById('contactsList');
            contactsList.innerHTML = '';

            let itemsToShow = [];
            if (currentTab === 'chats') {
                // Afficher tous les contacts (discussions privées)
                itemsToShow = contacts;
            } else if (currentTab === 'groups') {
                // Afficher tous les groupes
                itemsToShow = groups;
            } else if (currentTab === 'unread') {
                // Afficher uniquement les contacts ou groupes avec messages non lus
                itemsToShow = [];
                contacts.forEach(contact => {
                    if (contact.unreadCount && contact.unreadCount > 0) {
                        itemsToShow.push(contact);
                    }
                });
                groups.forEach(group => {
                    if (group.unreadCount && group.unreadCount > 0) {
                        itemsToShow.push(group);
                    }
                });
            } else if (currentTab === 'contacts') {
                // Trier les contacts par ordre alphabétique
                itemsToShow = [...contacts].sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));
            }

            itemsToShow.forEach(item => {
                const contactElement = createContactElement(item);
                contactsList.appendChild(contactElement);
            });
        }

        function createContactElement(contact) {
            const contactElement = document.createElement('div');
            contactElement.className = 'contact-item';
            
            const isGroup = contact.type === 'group';
            const avatarClass = isGroup ? 'avatar group-avatar' : 'avatar';
            const statusIndicator = isGroup ? '' : `<div class="${contact.isOnline ? 'online-status' : 'offline-status'}"></div>`;
            const groupIndicator = isGroup ? '<span class="group-indicator">Groupe</span>' : '';
            let avatarHtml = '';
            if (contact.avatar && contact.avatar !== '' && contact.avatar.indexOf('data/avatars/') === 0) {
                avatarHtml = `<img src='${contact.avatar}' alt='Avatar' style='width:40px;height:40px;border-radius:50%;object-fit:cover;'>`;
            } else if(contact.initial !== undefined){
                avatarHtml = `<div style='width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;${contact.initial_style}'>${contact.initial}</div>`;
            }else{
                avatarHtml = `<div style='width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;${contact.initial_style}'>${contact.avatar}</div>`;
            }
            contactElement.innerHTML = `
                <div class="avatar-container">
                    <div class="${avatarClass}">${avatarHtml}</div>
                    ${statusIndicator}
                </div>
                <div class="contact-info">
                    <div class="contact-name">
                        ${contact.name}
                        ${groupIndicator}
                    </div>
                    <div class="contact-last-message">${contact.lastMessage || (isGroup ? 'Groupe créé' : 'Aucun message')}</div>
                    ${contact.unreadCount && contact.unreadCount > 0 ? `<span style='color:#ef4444;font-size:12px;font-weight:bold;'>${contact.unreadCount} non lu${contact.unreadCount>1?'s':''}</span>` : ''}
                </div>
                <div class="contact-time">${contact.lastMessageTime || ''}</div>
            `;
            
            contactElement.onclick = () => openChat(contact);
            return contactElement;
        }

        function openChat(contact) {
            currentChat = contact;

            // Remettre à zéro le compteur de non lus
            contact.unreadCount = 0;
            updateContactInList(contact);

            // Mettre à jour l'interface
            document.getElementById('chatHeader').style.display = 'flex';
            document.getElementById('messageInputContainer').style.display = 'flex';
            document.getElementById('contactName').textContent = contact.name;
            const contactAvatarElement = document.getElementById('contactAvatar');
            if (contact.avatar && contact.avatar !== '' && contact.avatar.indexOf('data/avatars/') === 0) {
                contactAvatarElement.innerHTML = `<img src='${contact.avatar}' alt='Avatar' style='width:40px;height:40px;border-radius:50%;object-fit:cover;'>`;
            } else if(contact.initial !== undefined){
                contactAvatarElement.innerHTML = `<div style='width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;${contact.initial_style}'>${contact.initial}</div>`;
            }else{
                contactAvatarElement.innerHTML = `<div style='width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;${contact.initial_style}'>${contact.avatar}</div>`;
            }
            contactAvatarElement.className = contact.type === 'group' ? 'avatar group-avatar' : 'avatar';

            // Mettre à jour le statut
            const statusElement = document.getElementById('contactStatus');
            const statusTextElement = document.getElementById('contactStatusText');

            if (contact.type === 'group') {
                statusElement.style.display = 'none';
                statusTextElement.textContent = 'Groupe';
            } else {
                statusElement.style.display = 'block';
                if (contact.isOnline) {
                    statusElement.className = 'online-status';
                    statusTextElement.textContent = 'En ligne';
                } else {
                    statusElement.className = 'offline-status';
                    statusTextElement.textContent = 'Hors ligne';
                }
            }

            // Marquer le contact comme actif
            document.querySelectorAll('.contact-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');

            // Charger les messages
            loadMessages(contact.id, contact.type === 'group');
        }

        function loadMessages(contactId, isGroup = false) {
            const url = `chat.php?action=get_messages&contact_id=${encodeURIComponent(contactId)}&is_group=${isGroup}`;
            
            fetch(url)
                .then(response => response.json())
                .then(messages => {
                    // Filtrer les messages masqués pour l'utilisateur
                    fetch('data/messages_hidden_' + currentUser.id + '.json?ts=' + Date.now())
                        .then(r => r.ok ? r.json() : [])
                        .then(hiddenList => {
                            const hidden = Array.isArray(hiddenList) ? hiddenList : [];
                            const messagesContainer = document.getElementById('messagesContainer');
                            messagesContainer.innerHTML = '';
                            messages.forEach(message => {
                                if (!hidden.includes(message.id)) {
                                    const messageElement = createMessageElement(message, isGroup);
                                    messageElement.setAttribute('data-message-id', message.id);
                                    messagesContainer.appendChild(messageElement);
                                }
                            });
                            scrollToBottom();
                        });
                })
                .catch(error => {
                    console.error('Erreur lors du chargement des messages:', error);
                });
        }

        function createMessageElement(message, isGroup = false) {
            const messageElement = document.createElement('div');
            const isSent = message.sender === currentUser.id;
            messageElement.className = `message ${isSent ? 'sent' : 'received'}`;

            let senderName = '';
            if (isGroup && !isSent) {
                // Trouver le nom de l'expéditeur
                const sender = contacts.find(c => c.id === message.sender);
                senderName = sender ? sender.name : 'Utilisateur';
            }

            // Icônes SVG pour les statuts WhatsApp
            const icons = {
                sent: '<svg width="20" height="20" viewBox="0 0 24 24" style="vertical-align:middle;"><path d="M6 12l6 6 6-12" stroke="#111" stroke-width="2" fill="none"/></svg>', // 1 trait noir
                delivered: '<svg width="20" height="20" viewBox="0 0 24 24" style="vertical-align:middle;"><path d="M6 12l6 6 6-12" stroke="#111" stroke-width="2" fill="none"/><path d="M10 12l4 4 4-8" stroke="#111" stroke-width="2" fill="none"/></svg>', // 2 traits noirs
                read: '<svg width="20" height="20" viewBox="0 0 24 24" style="vertical-align:middle;"><path d="M6 12l6 6 6-12" stroke="#111" stroke-width="2" fill="none"/><path d="M10 12l4 4 4-8" stroke="#111" stroke-width="2" fill="none"/></svg>' // 2 traits noirs
            };

            // Déterminer le statut du message selon WhatsApp
            let statusIcon = '';
            let statusText = '';
            if (isSent) {
                if (message.status === 'read') {
                    statusIcon = icons.read;
                    statusText = '<span style="font-size:10px;color:#111;margin-left:4px;">Lu</span>';
                } else if (message.status === 'delivered') {
                    statusIcon = icons.delivered;
                    statusText = '<span style="font-size:10px;color:#111;margin-left:4px;">Reçu</span>';
                } else {
                    statusIcon = icons.sent;
                    statusText = '<span style="font-size:10px;color:#111;margin-left:4px;">Envoyé</span>';
                }
            }

            // Affichage amélioré pour les fichiers
            let contentHtml = message.content;
            // On gère les deux cas : avec ou sans data-original-name
            const fileRegex = /<a href=\\?"([^\"]+)\\?"([^>]*)>📎 Fichier<\/a>/;
            const match = contentHtml.match(fileRegex);
            if (match) {
                const fileUrl = match[1].replace(/\\"/g, '');
                let originalName = '';
                const attrs = match[2];
                const ext = fileUrl.split('.').pop().toLowerCase();
                // Chercher l'attribut data-original-name si présent
                const nameMatch = attrs.match(/data-original-name=\\?"([^\"]+)\\?"/);
                if (nameMatch) {
                    originalName = nameMatch[1];
                } else {
                    originalName = fileUrl.split('/').pop();
                }
                if (["jpg","jpeg","png","gif","bmp","webp"].includes(ext)) {
                    // Aperçu image sans nom
                    contentHtml = contentHtml.replace(fileRegex, `<br><img src='${fileUrl}' alt='Image' style='max-width:180px;max-height:180px;border-radius:8px;margin-top:6px;'>`);
                } else {
                    // Icône + nom original du fichier (nom en blanc)
                    contentHtml = contentHtml.replace(fileRegex, `<br><a href='${fileUrl}' target='_blank' style='color:#fff;text-decoration:underline;display:flex;align-items:center;gap:6px;'><span style='font-size:18px;'>📄</span> <span style='font-size:12px;'>${originalName}</span></a>`);
                }
            }

            // Ajout du menu contextuel pour suppression
            messageElement.innerHTML = `
                ${isGroup && !isSent ? `<div class="message-sender">${senderName}</div>` : ''}
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span>${contentHtml}</span>
                    ${isSent ? `<span class="message-status">${statusIcon}${statusText}</span>` : ''}
                </div>
                <div class="message-time">${formatTime(message.timestamp)}</div>
            `;

            return messageElement;
        }

        function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const fileInput = document.getElementById('fileInput');
            const content = messageInput.value.trim();
            const file = fileInput.files[0];

            if ((content || file) && currentChat) {
                sendBtn.disabled = true;

                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('recipient', currentChat.id);
                formData.append('message', content);
                formData.append('is_group', currentChat.type === 'group' ? 'true' : 'false');
                if (file) {
                    formData.append('file', file);
                }

                fetch('chat.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const messageElement = createMessageElement(data.message, currentChat.type === 'group');
                        document.getElementById('messagesContainer').appendChild(messageElement);

                        messageInput.value = '';
                        removeFilePreview(); // Masquer l'aperçu après envoi
                        fileInput.value = '';
                        scrollToBottom();

                        // Mise à jour du dernier message du contact
                        currentChat.lastMessage = content || (file ? 'Fichier envoyé' : '');
                        currentChat.lastMessageTime = formatTime(new Date());
                        // Déplacer le contact/groupe en haut de la liste
                        if (currentChat.type === 'user') {
                            contacts = contacts.filter(c => c.id !== currentChat.id);
                            contacts.unshift(currentChat);
                        } else {
                            groups = groups.filter(g => g.id !== currentChat.id);
                            groups.unshift(currentChat);
                        }
                        loadContacts();
                        showNotification('Message envoyé !', 'success');
                    } else {
                        showNotification('Erreur lors de l\'envoi du message', 'error');
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    showNotification('Erreur lors de l\'envoi du message', 'error');
                })
                .finally(() => {
                    sendBtn.disabled = false;
                });
            }
        }

        function startPolling() {
            // Vérifier les nouveaux messages toutes les 2 secondes
            pollingInterval = setInterval(() => {
                checkNewMessages();
            }, 2000);
        }

        function checkNewMessages() {
            fetch(`chat.php?action=get_new_messages&last_check=${encodeURIComponent(lastMessageCheck)}`)
                .then(response => response.json())
                .then(newMessages => {
                    if (newMessages.length > 0) {
                        newMessages.forEach(message => {
                            // Si c'est un message pour la conversation actuelle
                            if (currentChat && 
                                ((message.is_group && message.recipient === currentChat.id) ||
                                 (!message.is_group && message.sender === currentChat.id))) {
                                const messageElement = createMessageElement(message, message.is_group);
                                document.getElementById('messagesContainer').appendChild(messageElement);
                                scrollToBottom();
                            }
                            
                            // Mettre à jour le contact dans la liste
                            let contact;
                            if (message.is_group) {
                                contact = groups.find(g => g.id === message.recipient);
                            } else {
                                contact = contacts.find(c => c.id === message.sender);
                            }
                            
                            if (contact) {
                                contact.lastMessage = message.content;
                                contact.lastMessageTime = formatTime(message.timestamp);
                                // Incrémenter le compteur de non lus si le message n'est pas lu et n'est pas envoyé par l'utilisateur actuel
                                if (message.status !== 'read' && message.sender !== currentUser.id) {
                                    contact.unreadCount = (contact.unreadCount || 0) + 1;
                                }
                                updateContactInList(contact);
                            }
                            
                            // Afficher une notification si ce n'est pas la conversation actuelle
                            if (!currentChat || 
                                (message.is_group && message.recipient !== currentChat.id) ||
                                (!message.is_group && message.sender !== currentChat.id)) {
                                
                                let senderName;
                                if (message.is_group) {
                                    const sender = contacts.find(c => c.id === message.sender);
                                    const group = groups.find(g => g.id === message.recipient);
                                    senderName = `${sender ? sender.name : 'Utilisateur'} dans ${group ? group.name : 'Groupe'}`;
                                } else {
                                    senderName = contacts.find(c => c.id === message.sender)?.name || 'Utilisateur';
                                }
                                
                                showNotification(`${senderName}: ${message.content}`, 'info');
                            }
                        });
                        
                        lastMessageCheck = new Date().toISOString();
                    }
                })
                .catch(error => {
                    console.error('Erreur lors de la vérification des nouveaux messages:', error);
                });
        }

        function checkUserStatuses() {
            fetch('chat.php?action=get_user_statuses')
                .then(response => response.json())
                .then(statuses => {
                    userStatuses = statuses;
                    
                    // Mettre à jour les statuts des contacts
                    contacts.forEach(contact => {
                        const status = statuses[contact.id];
                        if (status) {
                            contact.isOnline = status.status === 'online';
                        }
                    });
                    
                    // Recharger la liste des contacts
                    loadContacts();
                    
                    // Mettre à jour le statut dans l'en-tête du chat
                    if (currentChat && currentChat.type !== 'group') {
                        const status = statuses[currentChat.id];
                        const statusElement = document.getElementById('contactStatus');
                        const statusTextElement = document.getElementById('contactStatusText');
                        
                        if (status && status.status === 'online') {
                            statusElement.className = 'online-status';
                            statusTextElement.textContent = 'En ligne';
                        } else {
                            statusElement.className = 'offline-status';
                            statusTextElement.textContent = 'Hors ligne';
                        }
                    }
                })
                .catch(error => {
                    console.error('Erreur lors de la vérification des statuts:', error);
                });
        }

        function updateUserStatus() {
            // Ping pour maintenir le statut en ligne
            fetch('chat.php?action=ping')
                .catch(error => {
                    console.error('Erreur lors de la mise à jour du statut:', error);
                });
        }

        function updateContactInList(contact) {
            const contactElements = document.querySelectorAll('.contact-item');
            contactElements.forEach(element => {
                const nameElement = element.querySelector('.contact-name');
                if (nameElement && nameElement.textContent.includes(contact.name)) {
                    const lastMessageElement = element.querySelector('.contact-last-message');
                    const timeElement = element.querySelector('.contact-time');
                    
                    if (lastMessageElement) {
                        lastMessageElement.textContent = contact.lastMessage || (contact.type === 'group' ? 'Groupe créé' : 'Aucun message');
                    }
                    if (timeElement) {
                        timeElement.textContent = contact.lastMessageTime || '';
                    }
                }
            });
        }

        function openCreateGroupModal() {
            const modal = document.getElementById('createGroupModal');
            const membersSelection = document.getElementById('membersSelection');
            
            // Vider la sélection des membres
            membersSelection.innerHTML = '';
            
            // Ajouter tous les contacts comme options
            contacts.forEach(contact => {
                const memberItem = document.createElement('div');
                memberItem.className = 'member-item';
                let avatarHtml = '';
                if (contact.avatar && contact.avatar !== '' && contact.avatar.indexOf('data/avatars/') === 0) {
                    avatarHtml = `<img src='${contact.avatar}' alt='Avatar' style='width:24px;height:24px;border-radius:50%;object-fit:cover;'>`;
                } else {
                    avatarHtml = `<div style='width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;${contact.initial_style}'>${contact.initial}</div>`;
                }
                memberItem.innerHTML = `
                    <input type="checkbox" class="member-checkbox" value="${contact.id}" id="member_${contact.id}">
                    <div class="avatar">${avatarHtml}</div>
                    <label for="member_${contact.id}">${contact.name}</label>
                `;
                membersSelection.appendChild(memberItem);
            });
            
            modal.classList.add('show');
        }

        function closeCreateGroupModal() {
            const modal = document.getElementById('createGroupModal');
            modal.classList.remove('show');
            
            // Réinitialiser le formulaire
            document.getElementById('createGroupForm').reset();
        }

        function createGroup() {
            const groupName = document.getElementById('groupName').value.trim();
            const groupDescription = document.getElementById('groupDescription').value.trim();
            const createBtn = document.getElementById('createGroupBtn');
            
            // Récupérer les membres sélectionnés
            const selectedMembers = [];
            document.querySelectorAll('.member-checkbox:checked').forEach(checkbox => {
                selectedMembers.push(checkbox.value);
            });
            
            if (!groupName) {
                showNotification('Le nom du groupe est requis', 'error');
                return;
            }
            
            if (selectedMembers.length === 0) {
                showNotification('Veuillez sélectionner au moins un membre', 'error');
                return;
            }
            
            createBtn.disabled = true;
            createBtn.textContent = 'Création en cours...';
            
            const formData = new FormData();
            formData.append('action', 'create_group');
            formData.append('group_name', groupName);
            formData.append('group_description', groupDescription);
            formData.append('members', JSON.stringify(selectedMembers));
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Ajouter le nouveau groupe à la liste
                    const newGroup = {
                        id: data.group.id,
                        name: data.group.name,
                        description: data.group.description,
                        avatar: data.group.avatar,
                        lastMessage: '',
                        lastMessageTime: '',
                        type: 'group'
                    };
                    
                    groups.push(newGroup);
                    
                    // Fermer le modal
                    closeCreateGroupModal();
                    
                    // Recharger les contacts
                    loadContacts();
                    
                    // Ouvrir le nouveau groupe
                    openChat(newGroup);
                    
                    showNotification('Groupe créé avec succès !', 'success');
                } else {
                    showNotification(data.error || 'Erreur lors de la création du groupe', 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la création du groupe', 'error');
            })
            .finally(() => {
                createBtn.disabled = false;
                createBtn.textContent = 'Créer le groupe';
            });
        }

        function scrollToBottom() {
            const messagesContainer = document.getElementById('messagesContainer');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function formatTime(timestamp) {
            // Afficher l'heure locale (Dakar/GMT+0)
            const date = new Date(timestamp);
            const hours = date.getHours().toString().padStart(2, '0');
            const minutes = date.getMinutes().toString().padStart(2, '0');
            return `${hours}:${minutes}`;
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification';
            
            if (type === 'error') {
                notification.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
            } else if (type === 'info') {
                notification.style.background = 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)';
            }
            
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        function switchTab(tab) {
            currentTab = tab;
            
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tab + 'Tab').classList.add('active');
            
            loadContacts();
        }

        function searchContacts(query) {
            const contactItems = document.querySelectorAll('.contact-item');
            contactItems.forEach(item => {
                const name = item.querySelector('.contact-name')?.textContent.toLowerCase();
                const message = item.querySelector('.contact-last-message')?.textContent.toLowerCase();
                
                if (name?.includes(query.toLowerCase()) || message?.includes(query.toLowerCase())) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }

        function toggleTheme() {
    const body = document.body;
    const themeToggle = document.getElementById('themeToggle');
    body.classList.toggle('dark');
    if (body.classList.contains('dark')) {
        themeToggle.innerHTML = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="5" fill="#FFD600"/>
            <g stroke="#FFD600" stroke-width="2">
                <line x1="12" y1="2" x2="12" y2="5"/>
                <line x1="12" y1="19" x2="12" y2="22"/>
                <line x1="2" y1="12" x2="5" y2="12"/>
                <line x1="19" y1="12" x2="22" y2="12"/>
                <line x1="4.22" y1="4.22" x2="6.34" y2="6.34"/>
                <line x1="17.66" y1="17.66" x2="19.78" y2="19.78"/>
                <line x1="4.22" y1="19.78" x2="6.34" y2="17.66"/>
                <line x1="17.66" y1="6.34" x2="19.78" y2="4.22"/>
            </g>
        </svg>`;
    } else {
        themeToggle.innerHTML = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none">
            <path d="M21 12.79A9 9 0 0112.79 3a7 7 0 100 14A9 9 0 0121 12.79z" fill="#764ba2"/>
        </svg>`;
    }
}


        function clearChat() {
            if (currentChat && confirm('Êtes-vous sûr de vouloir effacer cette conversation ?')) {
                const messagesContainer = document.getElementById('messagesContainer');
                messagesContainer.innerHTML = '<div class="empty-chat"><h3>Conversation effacée</h3><p>Commencez une nouvelle conversation</p></div>';
                
                showNotification('Conversation effacée', 'success');
            }
        }

        // Fonctions de déconnexion
        function showLogoutConfirm() {
            const modal = document.getElementById('logoutModal');
            modal.classList.add('show');
            
            // Fermer le modal en cliquant à l'extérieur
            modal.onclick = function(e) {
                if (e.target === modal) {
                    hideLogoutConfirm();
                }
            };
        }

        function hideLogoutConfirm() {
            const modal = document.getElementById('logoutModal');
            modal.classList.remove('show');
        }

        function logout() {
            // Arrêter le polling
            if (pollingInterval) {
                clearInterval(pollingInterval);
            }
            showNotification('Déconnexion en cours...', 'info');
            fetch('login.php?action=logout', {method:'POST'})
                .then(() => { window.location.href = 'login.php'; });
        }

        // Gérer la touche Escape pour fermer le modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideLogoutConfirm();
            }
        });

        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('createGroupModal');
            if (event.target === modal) {
                closeCreateGroupModal();
            }
        }

        // Nettoyer les intervalles quand la page se ferme
        window.addEventListener('beforeunload', function() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
            }
        });

        // Gérer la perte de focus pour arrêter le polling
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (pollingInterval) {
                    clearInterval(pollingInterval);
                }
            } else {
                startPolling();
            }
        });
    </script>
</body>
</html>