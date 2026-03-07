<?php
session_start();

/* FIXED ADMIN ACCOUNT */
$admin_email = "admin@nupost.com";
$admin_password = "admin123";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $email = $_POST["email"] ?? "";
  $password = $_POST["password"] ?? "";

  if ($email === $admin_email && $password === $admin_password) {

    $_SESSION["admin_logged_in"] = true;
    $_SESSION["admin_email"] = $email;

    header("Location: index.php");
    exit();

  } else {
    $error = "Invalid email or password.";
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>NUPost – Admin Login</title>

<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;300;400;700&family=Arimo:wght@400&display=swap" rel="stylesheet" />

<style>

/* =============================================
   DESIGN TOKENS
============================================= */
:root {

  --color-primary:#002366;
  --color-white:#ffffff;
  --color-black:#000000;
  --color-input-border:rgba(0,0,0,0.5);
  --color-placeholder:rgba(10,10,10,0.5);

  --font-inter:'Inter',sans-serif;
  --font-arimo:'Arimo',sans-serif;

  --fs-label:12px;
  --fs-placeholder:12px;
  --fs-password-dots:16px;
  --fs-button:12px;

  --fw-light:300;
  --fw-regular:400;
  --fw-thin:100;
  --fw-bold:700;

  --lh-label:18px;
  --lh-button:18px;

  --sp-32:32px;
  --sp-24:24px;
  --sp-12:12px;
  --sp-8:8px;

  --radius-card:8px;
  --radius-input:5px;
  --radius-btn:5px;

  --shadow-card:
    0px 10px 15px rgba(0,0,0,0.1),
    0px 4px 6px rgba(0,0,0,0.1);

  --card-width:448px;
}

/* RESET */

*{
box-sizing:border-box;
margin:0;
padding:0;
}

html,body{
height:100%;
}

body{
font-family:var(--font-inter);
}

/* LAYOUT */

.login{
position:relative;
width:100%;
min-height:100vh;
display:flex;
align-items:center;
justify-content:center;
overflow:hidden;
}

/* BACKGROUND */

.login__bg{
position:absolute;
inset:0;
z-index:0;
}

.login__bg img{
width:100%;
height:100%;
object-fit:cover;
}

/* CARD */

.login__card{
position:relative;
z-index:1;
background:var(--color-white);
width:var(--card-width);
border-radius:var(--radius-card);
box-shadow:var(--shadow-card);
padding:var(--sp-32);
display:flex;
flex-direction:column;
align-items:center;
}

/* LOGO */

.login__logo{
width:200px;
margin-bottom:24px;
}

.login__logo img{
width:100%;
}

/* FORM */

.login__form{
width:100%;
display:flex;
flex-direction:column;
gap:var(--sp-24);
}

.login__field{
display:flex;
flex-direction:column;
gap:var(--sp-8);
}

.login__label{
font-size:var(--fs-label);
font-weight:var(--fw-light);
}

.login__input{
width:100%;
height:44px;
border:1px solid var(--color-input-border);
border-radius:var(--radius-input);
padding:0 var(--sp-12);
font-size:var(--fs-placeholder);
outline:none;
}

.login__input:focus{
border-color:var(--color-primary);
}

.login__input--password{
font-family:var(--font-arimo);
font-size:var(--fs-password-dots);
letter-spacing:2px;
}

.login__button{
width:100%;
height:39px;
background:var(--color-primary);
color:var(--color-white);
border:none;
border-radius:var(--radius-btn);
font-weight:var(--fw-bold);
font-size:var(--fs-button);
cursor:pointer;
}

.login__button:hover{
opacity:.9;
}

/* ERROR */

.login__error{
color:red;
font-size:12px;
margin-top:10px;
text-align:center;
}

/* MOBILE */

@media(max-width:768px){

.login__card{
width:92%;
padding:28px 24px;
}

}

</style>
</head>

<body>

<main class="login">

<!-- BACKGROUND -->
<div class="login__bg">
<img src="assets/nubg1.png" alt="NU Lipa Campus">
</div>

<!-- LOGIN CARD -->
<section class="login__card">

<div class="login__logo">
<img src="assets/nupostlogo.png" alt="NUPost Logo">
</div>

<form class="login__form" method="POST">

<div class="login__field">
<label class="login__label">EMAIL ADDRESS:</label>
<input class="login__input" type="email" name="email" placeholder="your.email@nu-lipa.edu.ph" required>
</div>

<div class="login__field">
<label class="login__label">PASSWORD:</label>
<input class="login__input login__input--password" type="password" name="password" placeholder="••••••••" required>
</div>

<button class="login__button" type="submit">LOGIN</button>

<?php if($error): ?>
<div class="login__error"><?= $error ?></div>
<?php endif; ?>

</form>

</section>

</main>

</body>
</html>
