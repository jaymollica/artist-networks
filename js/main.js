$(function() {

    // select all on focus
    $("input[type='text']").on("click", function () {
        $(this).select();
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
            });
        },
    });

    // autocomplete results behavior
    function clickAutocompleteResult(e) {
        var ulan = e.target.id;
        var artist = e.target.text;

        $("#hint").val(artist);
        $("#searchUlan").val(ulan);

        $("#suggestion-results").empty();

        // submit form after selecting
        $("form#searchNetworks").submit();
    }

    $(document).on({
        ajaxStart: function() { 
            $("body").addClass("loading");    
        },
        ajaxStop: function() { 
            $("body").removeClass("loading"); 
        }    
    });

    // fetch data and build network visualization
    $("form#searchNetworks").submit(function (e) {
        e.preventDefault();

        var artist = $("#hint").val();
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
                        .force("center", d3.forceCenter(width / 2, 250));

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
                        .attr("stroke", "#fff")
                        .attr("stroke-width", 1.5)
                        .selectAll("circle")
                        .data(nodes)
                        .join("circle")
                        .attr("r", 5)
                        .attr("fill", "red")
                        .attr("class", "degree")
                        .call(drag(simulation));

                    node.append("title")
                        .text(d => d.artist);

                    node.attr("r", function(){
                        return 5;
                    });

                    simulation.on("tick", () => {
                        link
                            .attr("x1", d => d.source.x)
                            .attr("y1", d => d.source.y)
                            .attr("x2", d => d.target.x)
                            .attr("y2", d => d.target.y);

                        node
                            .attr("cx", d => d.x)
                            .attr("cy", d => d.y);
                    });

                    //invalidation.then(() => simulation.stop());

                    return svg.node();
                }

                height = 900;
                width = 900;

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

                $("#stage").empty();
                $("#stage").append(chart);


            }
        });

    });

});   