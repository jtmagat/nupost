    <?php
    session_start();
    require_once "../config/database.php";

    /* FIXED ADMIN ACCOUNT */
    $admin_email = "admin@nupost.com";
    $admin_password = "admin123";

    $error = "";

    if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";

    /* =========================
    CHECK ADMIN FIRST
    ========================= */

    if ($email === $admin_email && $password === $admin_password) {

    $_SESSION["role"] = "admin";
    $_SESSION["admin_email"] = $email;

    header("Location: ../admin/index.php");
    exit();

    }

    /* =========================
    CHECK REQUESTOR DATABASE
    ========================= */

    $query = mysqli_query($conn,"SELECT * FROM users WHERE email='$email' AND password='$password'");

    if(mysqli_num_rows($query) == 1){

    $user = mysqli_fetch_assoc($query);

    $_SESSION["role"] = "requestor";
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["name"] = $user["name"];

    header("Location: ../requestor/dashboard.php");
    exit();

    }else{

    $error = "Invalid email or password.";

    }

    }
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NUPost – Login</title>

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
    gap:24px;
    }

    .login__field{
    display:flex;
    flex-direction:column;
    gap:8px;
    }

    .login__label{
    font-size:12px;
    font-weight:300;
    }

    .login__input{
    width:100%;
    height:44px;
    border:1px solid var(--color-input-border);
    border-radius:var(--radius-input);
    padding:0 12px;
    font-size:12px;
    outline:none;
    }

    .login__input:focus{
    border-color:var(--color-primary);
    }

    .login__input--password{
    font-family:var(--font-arimo);
    letter-spacing:2px;
    }

    .login__button{
    width:100%;
    height:39px;
    background:var(--color-primary);
    color:white;
    border:none;
    border-radius:var(--radius-btn);
    font-weight:700;
    font-size:12px;
    cursor:pointer;
    }

    .login__button:hover{
    opacity:.9;
    }

    /* SIGNUP LINK */

    .signup-link{
    margin-top:15px;
    font-size:12px;
    text-align:center;
    }

    .signup-link a{
    color:var(--color-primary);
    font-weight:600;
    text-decoration:none;
    }

    .signup-link a:hover{
    text-decoration:underline;
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

    <!-- SIGN UP LINK -->
    <div class="signup-link">
    Don't have an account? <a href="registration.php">Sign Up</a>
    </div>

    </section>

    </main>

    </body>
    </html>
