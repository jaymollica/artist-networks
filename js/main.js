$(function() {

    // select all on focus
    $("input[type='text']").on("click", function () {
        $(this).select();
    });

    $('.artist-link').bind("click", clickArtistModalLink);

    $('#submitBacon').on("click", function() {
        $("#baconForm").submit();
        return;
    });

    $( "input[type='text']" ).on('input',function(e){

        var lenLimit = 24;

        if (/Mobi|Android/i.test(navigator.userAgent)) {
            var lenLimit = 15;
        }

        if( $(this).val().length > lenLimit ) {
            $(this).addClass( "long" )
        }
        else {
            $(this).removeClass( "long" );
        }
    });

    // autocomplete
    $( "#hint" ).autocomplete({

        minLength: 4,

        delay: 500,

        source: function( request, response ) {
            $("#suggestion-results").empty();
            $.ajax({
                url: "data.php",
                dataType: "json",
                data: {
                    q: request.term
                },
                success: function( data ) {

                    if(data.length == 0) {
                        var noResults = $("<p/>");

                                noResults.html("We could not find a match. Maybe try <a href='#' id=500006031 class='artist-link'>Andy Warhol</a> or <a href='#' id=500012368 class='artist-link'>Mary Cassatt</a>.");

                                noResults.children('a').bind("click", clickArtistModalLink);

                        
                        $("#bio").append(noResults);

                    } else {

                        $.each(data, function( index, value ) {
                            var a = $("<a></a>")
                            .text(value[0])
                            .attr("id", value[1])
                            .attr("href", "#")
                            .addClass("autocompleteOption")
                            .bind("click", clickAutocompleteResult);

                            var li = $("<li></li>").append(a);
                            $("#suggestion-results").append(li);
                        });

                    }
                }
            });
        },
    });

    // autocomplete results behavior
    function clickAutocompleteResult(e) {
        var ulan = e.target.id;
        var artist = e.target.text;

        $("#hint").val(artist);
        var lenLimit = 24;
        if (/Mobi|Android/i.test(navigator.userAgent)) {
            var lenLimit = 15;
        }

        if( artist.length > lenLimit ) {
            $("#hint").addClass( "long" );
        }
        else {
            $("#hint").removeClass( "long" );
        }

        $("#searchUlan").val(ulan);

        $("#suggestion-results").empty();

        $.ajax({
            url: "data.php",
            dataType: "text",
            data: {
                    note: ulan,
            },
            success: function(data) {
                var info = jQuery.parseJSON(data);

                var bio = $("<p/>");
                    bio.attr("class", "artist-bio")
                       .html(info.bio);

                var note = $("<p/>");
                    note.text(info.note);

                var rels = $("<ul/>");
                    rels.attr("class", "bio-relationships-list");

                var mob = $("<div/>");
                    mob.attr("id", "mobile-stage");

                $.each(info.rels, function( index, value ) {
                    var li = $("<li/>");
                        li.html(value)
                          .children().bind("click", clickArtistModalLink);

                    rels.append(li);
                });

                $("#bio").empty();
                $("#bio")
                    .append(bio)
                    .append(mob)
                    .append(note);
                $("#bio").append(rels);
            }

        });

        // submit form after selecting
        $("form#searchNetworks").submit();
    }

    $(document).on({
        ajaxStart: function() {
            $(".node-modal").remove();
            $("#bio").empty();
            $("body").addClass("loading");    
        },
        ajaxStop: function() { 
            $("body").removeClass("loading"); 
        }    
    });

    function closeModal() {
        $(this).closest(".node-modal").remove();
    }

    function clickArtistModalLink(e) {

        $("#hint").val($(this).text());

        var lenLimit = 24;
        if (/Mobi|Android/i.test(navigator.userAgent)) {
            var lenLimit = 15;
        }

        if( $("#hint").val().length > lenLimit ) {
            $("#hint").addClass( "long" );
        }
        else{
            $("#hint").removeClass( "long" );
        }

        $("#searchUlan").val($(this).attr("id"));

        $("#suggestion-results").empty();

        $.ajax({
            url: "data.php",
            dataType: "text",
            data: {
                    note: $(this).attr("id"),
            },
            success: function(data) {
                var info = jQuery.parseJSON(data);

                var bio = $("<p/>");
                    bio.attr("class", "artist-bio")
                       .html(info.bio);

                var note = $("<p/>");
                    note.text(info.note);

                var rels = $("<ul/>");
                    rels.attr("class", "bio-relationships-list");

                var mob = $("<div/>");
                    mob.attr("id", "mobile-stage");

                $.each(info.rels, function( index, value ) {
                    var li = $("<li/>");
                        li.html(value)
                          .children().bind("click", clickArtistModalLink);

                    rels.append(li);
                });

                $("#bio").empty();
                $("#bio")
                    .append(bio)
                    .append(mob)
                    .append(note);
                $("#bio").append(rels);                   
            }

        });
        // // submit form after selecting
        $("form#searchNetworks").submit();
    }

    // fetch data and build network visualization
    $("form#searchNetworks").submit(function (e) {
        e.preventDefault();

        var artist = $("#hint").val();

        var lenLimit = 24;
        if (/Mobi|Android/i.test(navigator.userAgent)) {
            var lenLimit = 15;
        }

        if( artist.length > lenLimit ) {
            $("#hint").addClass( "long" );
        }
        else{
            $("#hint").removeClass( "long" );
        }

        var ulan = $("#searchUlan").val();
        var degrees = 2;

        $.ajax({
            url: "data.php",
            dataType: "json",
            data: {
                    ulan: ulan,
                    degrees: degrees,
            },
            success: function(data) {
                
                var chart = function() {

                    const links = data.links.map(d => Object.create(d));
                    const nodes = data.nodes.map(d => Object.create(d));

                    const simulation = d3.forceSimulation(nodes)
                        .force("link", d3.forceLink(links).id(d => d.id).distance(60))
                        .force("charge", d3.forceManyBody())
                        .force("center", d3.forceCenter(width / 2, height / 2));

                    const svg = d3.create("svg")
                        .attr("viewBox", [0, 0, width, height]);

                    const link = svg.append("g")
                        .attr("stroke", "gray")
                        .attr("stroke-opacity", 0.6)
                        .selectAll("line")
                        .data(links)
                        .join("line")
                        .attr("stroke-width", d => Math.sqrt(d.value));

                    const node = svg.append("g")
                        .attr("stroke", "gray")
                        .attr("stroke-width", 1.5)
                        .selectAll("circle")
                        .data(nodes)
                        .join("circle")
                        .attr("r", function(e) {
                            var r = 5;
                            if(e.group == 0) {
                                r = 10;
                            } 
                            return r;
                        })
                        .attr("fill", function(e) {
                            var color = "white";
                            if(e.group == 0) {
                                color = "black";
                            } else if(e.group == 1) {
                                color = "white";
                            } else if(e.group == 2) {
                                color = "gray";
                            } else if(e.group == 3) {
                                color = "lightgray"
                            }
                            return color;
                        })
                        .attr("class", function(e) { 
                            return "node degree-"+e.group;
                        })
                        .on("click", showModal )
                        .call(drag(simulation));

                    node.append("title")
                        .text(d => d.artist);

                    // node.attr("r", function(){
                    //     return 5;
                    // });

                    function showModal(e) {

                        var y = $("#stage").offset().top + ( e.y - 50 );
                        var x = $("#stage").offset().left + ( e.x - 100 );

                        var div = $("<span/>");
                            div.attr("position", "absolute")
                               .attr("class", "node-modal")
                               .css({
                                    "top":y,
                                    "left":x,
                                    "background-color": "white",
                                })
                               .attr("class", "node-modal");

                        var artistLink = $("<a/>");
                            artistLink.bind("click", clickArtistModalLink)
                                      .attr("href", "#")
                                      .attr("class", "artist-link")
                                      .attr("id", e.id)
                                      .html(e.artist);

                        div.append(artistLink);

                        var closeA = $("<a/>");
                            closeA.bind("click", closeModal)
                                  .attr("href", "#")
                                  .attr("class", "close-modal")
                                  .html("&times;");

                        div.append(closeA);

                        $(".node-modal").remove();
                        $("body").append(div);

                    }

                    simulation.on("tick", () => {
                        link
                            .attr("x1", d => d.source.x)
                            .attr("y1", d => d.source.y)
                            .attr("x2", d => d.target.x)
                            .attr("y2", d => d.target.y);

                        node
                            .attr("cx", function(d) { 
                                return d.x = Math.max(5, Math.min(width - 5, d.x)); 
                            })
                            .attr("cy", function(d) { 
                                return d.y = Math.max(5, Math.min(height - 5, d.y));
                            });
                    });

                    return svg.node();
                }

                if (/Mobi|Android/i.test(navigator.userAgent)) {
                    // different stage dimensions if on mobile
                    height = 500;
                    width = 500;
                }
                else {
                    height = 700;
                    width = 900;
                }

                color = function() {
                    const scale = d3.scaleOrdinal(d3.schemeCategory10);
                    return d => scale(d.group);
                }

                drag = simulation => {
  
                    function dragstarted(d) {
                        if (!d3.event.active) simulation.alphaTarget(0.3).restart();
                        d.fx = d.x;
                        d.fy = d.y;
                    }
                      
                    function dragged(d) {
                        d.fx = d3.event.x;
                        d.fy = d3.event.y;
                    }
                      
                    function dragended(d) {
                        if (!d3.event.active) simulation.alphaTarget(0);
                        d.fx = null;
                        d.fy = null;
                    }
                      
                    return d3.drag()
                        .on("start", dragstarted)
                        .on("drag", dragged)
                        .on("end", dragended);
                }

                $("#mobile-stage").empty();
                $("#mobile-stage").append(chart);

                $("#stage").empty();
                $("#stage").append(chart);

            }
        });

    });


    // fetch data and build bacon visualization
    $("form#baconForm").submit(function (e) {

        e.preventDefault();
        var ulan1 = $("#searchUlan1").val();
        var ulan2 = $("#searchUlan2").val();
        var bacon = 1;

        $.ajax({
            url: "data.php",
            dataType: "json",
            data: {
                    bacon: bacon,
                    ulan1: ulan1,
                    ulan2, ulan2,
            },
            success: function(data) {
                
                var chart = function() {

                    const links = data.links.map(d => Object.create(d));
                    const nodes = data.nodes.map(d => Object.create(d));

                    var node_num = nodes.length;

                    if(node_num === 0) {
                        var noResults = $("<div/>");
                            noResults.attr("class", "nobacon")
                                .html("<p>No connections could be found, please try again.</p>");
                        $("#bacon-stage").append(noResults);

                        return;
                    }

                    var max_group = Math.max.apply(Math, nodes.map(function(o) { return o.group; }))
                    
                    const simulation = d3.forceSimulation(nodes)
                        .force("link", d3.forceLink(links).id(d => d.id).distance(60))
                        .force("charge", d3.forceManyBody())
                        .force("x", d3.forceX(function(d){
                            for (let i = 0; i < node_num; i++) {
                              if (d.group === i) {
                                return i * width / node_num;
                              }
                              else {
                                continue;
                              }
                            }
                        }))
                        .force("y", d3.forceY(function(d){
                            for (let i = 0; i < node_num; i++) {
                              if (d.group === i) {
                                return i * height / node_num;
                              }
                              else {
                                continue;
                              }
                            }
                        }))
                        .force("center", d3.forceCenter(width/2, height/2));

                    const svg = d3.create("svg")
                        .attr("viewBox", [0, 0, width, height]);

                    const link = svg.append("g")
                        .attr("stroke", "#999")
                        .attr("stroke-opacity", 0.6)
                        .selectAll("line")
                        .data(links)
                        .join("line")
                        .attr("stroke-width", d => Math.sqrt(d.value));

                    const node = svg.append("g")
                        .attr("stroke", "white")
                        .attr("stroke-width", 1.5)
                        .selectAll("circle")
                        .data(nodes)
                        .join("circle")
                        .attr("r", function(e) {
                            var r = 5;
                            if(e.group == 0) {
                                r = 10;
                            } 
                            return r;
                        })
                        .attr("fill", function(e) {

                            var color = "black";
                            if(e.group == 0) {
                                color = "white";

                            } else if(e.target == true) {
                                color = "white";
                            } else  {
                                color = "black";
                            } 

                            return color;
                        })
                        .attr("class", function(e) { 

                            if(e.target === true) {
                                return "node bacon max degree-"+e.group;
                            }
                            else {
                                return "node bacon degree-"+e.group;
                            }
                            
                        })
                        .on("mouseenter", showModal )
                        .on("mouseleave", hideModal )
                        .call(drag(simulation));

                    node.append("title")
                        .text(d => d.artist);

                    function hideModal(e) {
                        setTimeout(function () {
                                $(".node-modal").fadeOut(800, function() { $(".node-modal").remove(); });
                        }, 3000);
                    }

                    function showModal(e) {

                        var y = $("#bacon-stage").offset().top + ( e.y - 50 );
                        var x = $("#bacon-stage").offset().left + ( e.x - 100 );

                        var div = $("<span/>");
                            div.attr("position", "absolute")
                               .attr("class", "node-modal")
                               .css({
                                    "top":y,
                                    "left":x,
                                    "background-color": "white",
                                })
                               .attr("class", "node-modal");

                        var artistLink = $("<p/>");
                            artistLink.bind("click", clickArtistModalLink)
                                      .attr("class", "artist-tooltip")
                                      .attr("id", e.id)
                                      .html(e.artist);

                        div.append(artistLink);

                        $(".node-modal").remove();
                        $("body").append(div);

                    }

                    simulation.on("tick", () => {
                        link
                            .attr("x1", d => d.source.x)
                            .attr("y1", d => d.source.y)
                            .attr("x2", d => d.target.x)
                            .attr("y2", d => d.target.y);

                        node
                            .attr("cx", function(d) { 
                                return d.x = Math.max(5, Math.min(width - 5, d.x)); 
                            })
                            .attr("cy", function(d) { 
                                return d.y = Math.max(5, Math.min(height - 5, d.y));
                            });
                    });

                    return svg.node();
                }

                if (/Mobi|Android/i.test(navigator.userAgent)) {
                    // different stage dimensions if on mobile
                    height = 500;
                    width = 200;
                }
                else {
                    height = 500;
                    width = 1000;
                }

                color = function() {
                    const scale = d3.scaleOrdinal(d3.schemeCategory10);
                    return d => scale(d.group);
                }

                drag = simulation => {
  
                    function dragstarted(d) {
                        if (!d3.event.active) simulation.alphaTarget(0.3).restart();
                        d.fx = d.x;
                        d.fy = d.y;
                    }
                      
                    function dragged(d) {
                        d.fx = d3.event.x;
                        d.fy = d3.event.y;
                    }
                      
                    function dragended(d) {
                        if (!d3.event.active) simulation.alphaTarget(0);
                        d.fx = null;
                        d.fy = null;
                    }
                      
                    return d3.drag()
                        .on("start", dragstarted)
                        .on("drag", dragged)
                        .on("end", dragended);
                }

                $("#bacon-stage").empty();
                $("#bacon-stage").append(chart);

            }
        });

    });

    $("#bacon-hint1, #bacon-hint2").autocomplete({

        minLength: 4,
        delay: 500,

        source: function( request, response ) {
            var inputID = $(this.element).prop("id");
            $("#suggestion-results").empty();
            $.ajax({
                url: "data.php",
                dataType: "json",
                data: {
                    q: request.term
                },
                success: function( data ) {

                    if(data.length == 0) {
                        
                    } else {

                        if(inputID == "bacon-hint1") {
                            $("#bacon-1-autosuggest").empty();
                        }
                        else if(inputID == "bacon-hint2") {
                            $("#bacon-2-autosuggest").empty();
                        }

                        $.each(data, function( index, value ) {
                            var a = $("<a></a>")
                            .text(value[0])
                            .attr("id", value[1])
                            .attr("href", "#")
                            .attr("data-source-id", inputID)
                            .addClass("baconOption")
                            .bind("click", clickAutocompleteBacon);

                            var li = $("<li></li>").append(a);
                            if(inputID == "bacon-hint1") {
                                $("#bacon-1-autosuggest").append(li);
                            }
                            else if(inputID == "bacon-hint2") {
                                $("#bacon-2-autosuggest").append(li);
                            }
                        });
                    }
                }
            });
        },        
    });

    function clickAutocompleteBacon(e) {

        var ulan = e.target.id;
        var artist = e.target.text;
        var inputID = e.target.attributes[2].value;

        if(inputID == "bacon-hint1") {
            $("#bacon-hint1").val(artist);
            $("#searchUlan1").val(ulan);
            $("#bacon-1-autosuggest").empty();
        }
        else if(inputID == "bacon-hint2") {
            $("#bacon-hint2").val(artist);
            $("#bacon-2-autosuggest").empty();
            $("#searchUlan2").val(ulan);
        }
    }

});   