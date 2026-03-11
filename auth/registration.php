<?php
session_start();
require_once "../config/database.php";

$error = "";
$success = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm = $_POST["confirm_password"];

    if($password !== $confirm){
        $error = "Passwords do not match.";
    } else {

        $check = mysqli_query($conn,"SELECT * FROM users WHERE email='$email'");

        if(mysqli_num_rows($check) > 0){
            $error = "Email already registered.";
        } else {
            mysqli_query($conn,"INSERT INTO users (name,email,password,role) 
            VALUES ('$name','$email','$password','requestor')");

            $success = "Account created successfully. You may login now.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NUPost – Register</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;300;400;700&family=Arimo:wght@400&display=swap" rel="stylesheet">

<style>
:root{
    --color-primary:#002366;
    --color-white:#ffffff;
    --color-input-border:rgba(0,0,0,0.5);

    --font-inter:'Inter',sans-serif;
    --font-arimo:'Arimo',sans-serif;

    --radius-card:8px;
    --radius-input:5px;
    --radius-btn:5px;

    --shadow-card:
    0px 10px 15px rgba(0,0,0,0.1),
    0px 4px 6px rgba(0,0,0,0.1);

    --card-width:448px;
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

html,body{
    height:100%;
    font-family:var(--font-inter);
}

.register{
    position:relative;
    width:100%;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

/* BACKGROUND */
.register__bg{
    position:absolute;
    inset:0;
    z-index:0;
}

.register__bg img{
    width:100%;
    height:100%;
    object-fit:cover;
}

/* CARD */
.register__card{
    position:relative;
    z-index:1;
    background:white;
    width:var(--card-width);
    border-radius:var(--radius-card);
    box-shadow:var(--shadow-card);
    padding:32px;
    display:flex;
    flex-direction:column;
    align-items:center;
}

/* LOGO */
.register__logo{
    width:200px;
    margin-bottom:24px;
}

.register__logo img{
    width:100%;
}

/* FORM */
.register__form{
    width:100%;
    display:flex;
    flex-direction:column;
    gap:20px;
}

.register__field{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.register__label{
    font-size:12px;
    font-weight:300;
}

.register__input{
    width:100%;
    height:44px;
    border:1px solid var(--color-input-border);
    border-radius:var(--radius-input);
    padding:0 12px;
    font-size:12px;
    outline:none;
}

.register__input:focus{
    border-color:var(--color-primary);
}

.register__button{
    width:100%;
    height:40px;
    background:var(--color-primary);
    color:white;
    border:none;
    border-radius:var(--radius-btn);
    font-weight:700;
    font-size:12px;
    cursor:pointer;
}

.register__button:hover{
    opacity:.9;
}

/* MESSAGE */
.message{
    font-size:12px;
    text-align:center;
    margin-top:10px;
}

.error{
    color:red;
}

.success{
    color:green;
}

/* LOGIN LINK */
.login-link{
    margin-top:15px;
    font-size:12px;
    text-align:center;
}

.login-link a{
    color:var(--color-primary);
    text-decoration:none;
    font-weight:600;
}

@media(max-width:768px){
    .register__card{
        width:92%;
        padding:28px 24px;
    }
}
</style>
</head>

<body>
<main class="register">

<!-- BACKGROUND -->
<div class="register__bg">
    <img src="assets/nubg1.png" alt="NU Lipa Campus">
</div>

<section class="register__card">

<div class="register__logo">
    <img src="assets/nupostlogo.png" alt="NUPost Logo">
</div>

<form class="register__form" method="POST">

<div class="register__field">
    <label class="register__label">FULL NAME:</label>
    <input class="register__input" type="text" name="name" required>
</div>

<div class="register__field">
    <label class="register__label">EMAIL ADDRESS:</label>
    <input class="register__input" type="email" name="email" required>
</div>

<div class="register__field">
    <label class="register__label">PASSWORD:</label>
    <input class="register__input" type="password" name="password" required>
</div>

<div class="register__field">
    <label class="register__label">CONFIRM PASSWORD:</label>
    <input class="register__input" type="password" name="confirm_password" required>
</div>

<button class="register__button" type="submit">REGISTER</button>

<?php if($error): ?>
<div class="message error"><?= $error ?></div>
<?php endif; ?>

<?php if($success): ?>
<div class="message success"><?= $success ?></div>
<?php endif; ?>

</form>

<div class="login-link">
Already have an account?  
<a href="login.php">Login here</a>
</div>

</section>
</main>
</body>
</html>
