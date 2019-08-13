<!doctype html>
<html class="no-js" lang="">

<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Medias Res: Explore the social networks of artists</title>
  <meta name="title" content="Medias Res: Explore the social networks of artists">
  <meta name="description" content="In medias res is Latin for “in the middle of things” and is used to describe a story that picks up somewhere in between the beginning and the end of events. This site is meant to illustrate that great artists often emerge out of prexisting movements or influences before going on to change culture and, in turn, influence future artists.">

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://www.medias-res.net/">
  <meta property="og:title" content="Medias Res: Explore the social networks of artists">
  <meta property="og:description" content="Explore the social networks of artists">
  <meta property="og:image" content="tile.png">

  <!-- Twitter -->
  <meta property="twitter:card" content="summary_large_image">
  <meta property="twitter:url" content="https://www.medias-res.net/">
  <meta property="twitter:title" content="Medias Res: Explore the social networks of artists">
  <meta property="twitter:description" content="Explore the social networks of artists">
  <meta property="twitter:image" content="tile.png">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="icon.png">
  <!-- Place favicon.ico in the root directory -->

  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
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
        <div id="bio">
          <p class="artist-bio">Search for an artist to explore their social network.</p><p>Not sure where to start? Try <a href="#" class="artist-link" id="500018666">Georgia O&rsquo;Keefe</a> or <a href="#" class="artist-link" id="500009666">Pablo Picasso</a>.
        </div>
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
        <li><a href="/">medias-res.net</a></li>
        <li>Explore the social networks of artists</li>
        <li class="lede">by <a href="https://www.jaymollica.com">Jay Mollica</a><li>
        <li><a href="/about.html">About</a></li>
        <li>&copy; 2019</li>
      </ul>
    </div>
  </div>
  
  <script src="js/vendor/modernizr-3.6.0.min.js"></script>
  <script src="js/vendor/jquery-3.3.1.min.js"></script>
  <script>window.jQuery || document.write('<script src="js/vendor/jquery-3.3.1.min.js"><\/script>')</script>
  <script src="js/vendor/jquery-ui.js"></script>
  <script src="js/vendor/d3.v5.min.js"></script>
  <link rel="stylesheet" type="text/css" href="css/vendor/jquery-ui.css" />
  <script src="js/plugins.js"></script>
  <script src="js/main.js"></script>

  <!-- Global site tag (gtag.js) - Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-145586582-1"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'UA-145586582-1');
  </script>

</body>

</html>
