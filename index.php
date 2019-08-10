<!doctype html>
<html class="no-js" lang="">

<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Social Networks of Artists</title>
  <meta name="description" content="">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="icon.png">
  <!-- Place favicon.ico in the root directory -->

  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
  <style>

    html, body, ul, li, p, div, input {
      font-family: "Helvetica Neue",Helvetica,Arial,sans-serif;
    }

    html, body {
      overflow:hidden;
    }

    .link {
      stroke: #aaa;
    }

    .node text {
      stroke:#333;
      cursos:pointer;
    }

    .node circle{
      stroke:#fff;
      stroke-width:3px;
      fill:#555;
    }

    .search-wrapper {
      width:40%;
      float: left;
    }

    #stage {
      width: 60%;
      height: 100%;
      float: left;
    }

      /* Start by setting display:none to make this hidden.
     Then we position it in relation to the viewport window
     with position:fixed. Width, height, top and left speak
     for themselves. Background we set to 80% white with
     our animation centered, and no-repeating */
    .modal {
        display:    none;
        position:   fixed;
        z-index:    1000;
        top:        0;
        left:       0;
        height:     100%;
        width:      100%;
        background: rgba( 255, 255, 255, .8 ) 
                    url('http://i.stack.imgur.com/FhHRx.gif') 
                    50% 50% 
                    no-repeat;
    }

    /* When the body has the loading class, we turn
       the scrollbar off with overflow:hidden */
    body.loading .modal {
        overflow: hidden;   
    }

    /* Anytime the body has the loading class, our
       modal element will be visible */
    body.loading .modal {
        display: block;
    }

    .search-wrapper input {
      width: 100%;
      font-size: 36px;
      border: none;
      border-bottom: 2px solid black;
      font-weight: 400;
    }

    .search-wrapper input::placeholder {
      color: lightgray;
      font-weight: 200;
    }

    .search-wrapper-inner {
      margin-top:50px;
      margin-left:50px;
    }

    .suggestion-list {
      list-style-type: none;
      padding-left: 0;
    }

    .footer {
      position: fixed;
      left: 0;
      bottom: 0;
      width: 100%;
      background-color: #F8F9F9;
      color: black;
      text-align: left;
      height: 150px;
      padding-left:50px;
    }

    .footer ul {
      list-style-type: none;
      padding-left: 0;
    }

  </style>
</head>

<body>
  <!--[if lte IE 9]>
    <p class="browserupgrade">You are using an <strong>outdated</strong> browser. Please <a href="https://browsehappy.com/">upgrade your browser</a> to improve your experience and security.</p>
  <![endif]-->

  <!-- Add your site or application content here -->
  <div class="container">
    <div class="search-wrapper">
      <div class="search-wrapper-inner">
        <form id="searchNetworks">
          <input type="text" class="network-search-box" id="hint" placeholder="Type to search..." />
          <input type="hidden" id="searchUlan" value="" />
        </form>
        <ul id="suggestion-results" class="suggestion-list"></ul>
      </div>
    </div>
    <div class="modal">
    </div>
    <div id="stage">

    </div>
  </div>
  <div class="footer">
    <div class="info">
      <ul>
        <li class="lede">by <a href="https://www.jaymollica.com">Jay Mollica</a><li>
        <li>&copy; 2019</li>
      </ul>
    </div>
  </div>

  <script src="js/vendor/modernizr-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
  <script>window.jQuery || document.write('<script src="js/vendor/jquery-3.3.1.min.js"><\/script>')</script>
  <script src="//code.jquery.com/ui/1.11.1/jquery-ui.js"></script>
  <script src="https://d3js.org/d3.v5.min.js"></script>
  <link rel="stylesheet" type="text/css" href="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css" />
  <script src="js/plugins.js"></script>
  <script src="js/main.js"></script>

  <!-- Google Analytics: change UA-XXXXX-Y to be your site's ID. -->
  <script>
    window.ga = function () { ga.q.push(arguments) }; ga.q = []; ga.l = +new Date;
    ga('create', 'UA-XXXXX-Y', 'auto'); ga('send', 'pageview')
  </script>
  <script src="https://www.google-analytics.com/analytics.js" async defer></script>
</body>

</html>
