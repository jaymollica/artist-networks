$(function() {

    // keep footer copyright year fresh
    $(".current-year").text(new Date().getFullYear());

    // select all on focus
    $("input[type='text']").on("click", function () {
        $(this).select();
    });

    function humanizeName(name) {
        if (!name) return name;
        var parts = String(name).split(',').map(function(s) { return s.trim(); });
        if (parts.length < 2) return name;
        return parts.slice(1).join(' ') + ' ' + parts[0];
    }
    function urlUlan() {
        return new URLSearchParams(window.location.search).get('ulan');
    }
    function urlDepth() {
        var d = parseInt(new URLSearchParams(window.location.search).get('depth'), 10);
        return d === 2 ? 2 : 1;
    }
    function syncUrlToUlan(ulan, replace) {
        var depth = $("#toggle-depth").hasClass("active") ? 2 : 1;
        var url = window.location.pathname + '?ulan=' + ulan + (depth === 2 ? '&depth=2' : '');
        if (window.location.pathname + window.location.search === url) return;
        if (replace) history.replaceState({ ulan: ulan, depth: depth }, '', url);
        else         history.pushState({ ulan: ulan, depth: depth },  '', url);
    }
    var inHistoryNav = false;
    window.addEventListener('popstate', function(ev) {
        var u = (ev.state && ev.state.ulan) || urlUlan();
        if (!u) return;
        inHistoryNav = true;
        $("#searchUlan").val(u);
        $("form#searchNetworks").submit();
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
                global: false,
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

    // Tracks the element that opened the artist-network modal so we can
    // return focus to it on close (WCAG 2.4.3 Focus Order).
    var artistModalReturnFocus = null;
    function closeModal() {
        $(".node-modal").remove();
        $(document).off("keydown.artistModal");
        if (artistModalReturnFocus && typeof artistModalReturnFocus.focus === "function") {
            try { artistModalReturnFocus.focus(); } catch (_) {}
        }
        artistModalReturnFocus = null;
    }

    // dismiss any open modal when clicking outside it. Modal-opening clicks
    // (on a node circle) stop propagation so they don't trigger this.
    $(document).on("click.modalDismiss", function(e) {
        if (!$(e.target).closest(".node-modal").length && $(".node-modal").length) {
            closeModal();
        }
    });

    function loadArtistBioPanel(ulan) {
        $.ajax({
            url: "data.php",
            dataType: "text",
            global: false,
            data: { note: ulan },
            success: function(data) {
                var info = jQuery.parseJSON(data);

                var bio = $("<p/>").attr("class", "artist-bio").html(info.bio);
                var note = $("<p/>").text(info.note);
                var rels = $("<ul/>").attr("class", "bio-relationships-list");
                var mob = $("<div/>").attr("id", "mobile-stage");
                // When Getty has no recorded relationships for this artist,
                // make that explicit so users don't think the page is broken.
                var noRels = (!info.rels || info.rels.length === 0)
                    ? $("<p/>").attr("class", "no-rels-notice")
                        .text("Getty ULAN has no relationships on file for this artist yet. The bio is shown below.")
                    : null;

                $.each(info.rels || [], function(index, value) {
                    var li = $("<li/>");
                    li.html(value).children().bind("click", clickArtistModalLink);
                    rels.append(li);
                });

                $("#bio").empty()
                    .append(bio)
                    .append(mob)
                    .append(note);
                if (noRels) $("#bio").append(noRels);
                $("#bio").append(rels);
            }
        });
    }

    function clickArtistModalLink(e) {
        if (e && e.preventDefault) e.preventDefault();
        $("#hint").val($(this).text());

        var lenLimit = 24;
        if (/Mobi|Android/i.test(navigator.userAgent)) {
            var lenLimit = 15;
        }

        if ($("#hint").val().length > lenLimit) $("#hint").addClass("long");
        else                                    $("#hint").removeClass("long");

        $("#searchUlan").val($(this).attr("id"));
        $("#suggestion-results").empty();
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
        var depth = $("#toggle-depth").hasClass("active") ? 2 : 1;

        $.ajax({
            url: "data.php",
            dataType: "json",
            data: {
                    ulan:  ulan,
                    depth: depth,
            },
            success: function(data) {
                
                var chart = function(layoutMode) {

                    const links = data.links.map(d => Object.create(d));
                    const nodes = data.nodes.map(d => Object.create(d));

                    // compute per-node layout targets for the chosen mode
                    const centerNode = nodes.find(n => n.group === 0);
                    const centerYear = centerNode && centerNode.birth_year;
                    const years     = nodes.map(n => n.birth_year).filter(y => y);
                    const minYear   = years.length ? d3.min(years) : 1500;
                    const maxYear   = years.length ? d3.max(years) : 2000;
                    const yScale    = d3.scaleLinear().domain([minYear, maxYear]).range([60, height - 60]);
                    const colX      = { mentorship: 0.15, family: 0.35, self: 0.5, affiliation: 0.65, collaboration: 0.85, other: 0.5 };
                    const angles    = { mentorship: -Math.PI/2, family: 0, affiliation: Math.PI/2, collaboration: Math.PI, other: -Math.PI/4 };
                    nodes.forEach(d => {
                        d.tx = null; d.ty = null;
                        const isFirst = d.group === 0 || d.group === 1;
                        if (layoutMode === 'grid') {
                            if (!isFirst) return;
                            const cat = d.group === 0 ? 'self' : (d.category || 'other');
                            d.tx = (colX[cat] || 0.5) * width;
                            d.ty = d.birth_year ? yScale(d.birth_year) : (height - 40);
                        } else if (layoutMode === 'clock') {
                            if (d.group === 0) { d.tx = width / 2; d.ty = height / 2; return; }
                            if (!isFirst) return;
                            const baseA = (angles[d.category || 'other'] !== undefined) ? angles[d.category || 'other'] : 0;
                            const jitter = ((Math.abs(d.id) % 7) - 3) * 0.12;
                            const a = baseA + jitter;
                            const yearOff = (d.birth_year && centerYear) ? (d.birth_year - centerYear) : 0;
                            const minR = 70, maxR = Math.min(width, height) * 0.42;
                            const t = Math.min(1, Math.abs(yearOff) / 150);
                            const r = minR + (maxR - minR) * t;
                            d.tx = width / 2 + r * Math.cos(a);
                            d.ty = height / 2 + r * Math.sin(a);
                        }
                    });

                    const simulation = d3.forceSimulation(nodes)
                        .force("link", d3.forceLink(links).id(d => d.id).distance(50).strength(0.15))
                        .force("charge", d3.forceManyBody().strength(-12))
                        .force("collide", d3.forceCollide(7))
                        .force("x", d3.forceX(d => d.tx != null ? d.tx : width / 2).strength(d => d.tx != null ? 1.0 : 0.04))
                        .force("y", d3.forceY(d => d.ty != null ? d.ty : height / 2).strength(d => d.ty != null ? 1.0 : 0.04));

                    const svg = d3.create("svg")
                        .attr("viewBox", [0, 0, width, height])
                        .attr("role", "img")
                        .attr("aria-label",
                            "Force-directed graph of " + nodes.length +
                            " artists connected by " + links.length +
                            " relationships. Use Tab to step through artists and Enter to open details.");

                    // scaffolding: axis labels and guides
                    const guides = svg.append("g").attr("class", "layout-guides");
                    if (layoutMode === 'grid') {
                        const cols = [
                            { cat: 'mentorship',    label: 'Mentorship' },
                            { cat: 'family',        label: 'Family' },
                            { cat: 'self',          label: 'Self' },
                            { cat: 'affiliation',   label: 'Affiliation' },
                            { cat: 'collaboration', label: 'Collaboration' },
                        ];
                        cols.forEach((c, i) => {
                            const x = colX[c.cat] * width;
                            guides.append("line")
                                .attr("x1", x).attr("x2", x)
                                .attr("y1", 36).attr("y2", height - 12)
                                .attr("stroke", "#333").attr("stroke-dasharray", "2,4");
                            guides.append("text")
                                .attr("x", x).attr("y", 22)
                                .attr("text-anchor", "middle")
                                .attr("fill", "#999").attr("font-size", 12)
                                .text(c.label);
                        });
                        if (years.length) {
                            const yearTicks = d3.ticks(minYear, maxYear, 4);
                            yearTicks.forEach(yr => {
                                const y = yScale(yr);
                                guides.append("text")
                                    .attr("x", 6).attr("y", y + 4)
                                    .attr("fill", "#666").attr("font-size", 10)
                                    .text(yr);
                            });
                        }
                    } else if (layoutMode === 'clock') {
                        const cx = width / 2, cy = height / 2;
                        const minR = 70, maxR = Math.min(width, height) * 0.42;
                        [50, 100, 150].forEach(yrs => {
                            const t = Math.min(1, yrs / 150);
                            const r = minR + (maxR - minR) * t;
                            guides.append("circle")
                                .attr("cx", cx).attr("cy", cy).attr("r", r)
                                .attr("fill", "none").attr("stroke", "#333")
                                .attr("stroke-dasharray", "2,4");
                            guides.append("text")
                                .attr("x", cx + 4).attr("y", cy - r - 2)
                                .attr("fill", "#666").attr("font-size", 10)
                                .text("±" + yrs + "y");
                        });
                        const slices = [
                            { cat: 'mentorship',    angle: -Math.PI/2, label: 'Mentorship' },
                            { cat: 'family',        angle: 0,          label: 'Family' },
                            { cat: 'affiliation',   angle: Math.PI/2,  label: 'Affiliation' },
                            { cat: 'collaboration', angle: Math.PI,    label: 'Collaboration' },
                        ];
                        slices.forEach(s => {
                            const lx = cx + (maxR + 16) * Math.cos(s.angle);
                            const ly = cy + (maxR + 16) * Math.sin(s.angle);
                            guides.append("text")
                                .attr("x", lx).attr("y", ly + 4)
                                .attr("text-anchor", "middle")
                                .attr("fill", "#999").attr("font-size", 12)
                                .text(s.label);
                        });
                    }

                    const linkColor = {
                        mentorship:    "#5b9bd5",
                        family:        "#e57373",
                        affiliation:   "#81c784",
                        collaboration: "#ba68c8",
                        other:         "#888"
                    };
                    const link = svg.append("g")
                        .attr("stroke-opacity", 0.7)
                        .selectAll("line")
                        .data(links)
                        .join("line")
                        .attr("stroke", d => linkColor[d.category] || linkColor.other)
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
                        .attr("tabindex", 0)
                        .attr("role", "button")
                        .attr("aria-label", function(d) {
                            var deg = d.group === 0 ? "searched artist" :
                                      d.group === 1 ? "first-degree connection" :
                                      d.group === 2 ? "second-degree connection" :
                                      "third-degree connection";
                            var yrs = d.birth_year ? " (b. " + d.birth_year + ")" : "";
                            return humanizeName(d.artist) + yrs + ", " + deg;
                        })
                        .on("click", showModal )
                        .on("keydown", function(d) {
                            var k = d3.event.key;
                            if (k === "Enter" || k === " " || k === "Spacebar") {
                                d3.event.preventDefault();
                                showModal.call(this, d);
                            }
                        })
                        .on("mouseenter.label", function(d) { showNodeHover(this, humanizeName(d.artist)); })
                        .on("mouseleave.label", hideNodeHover)
                        .on("focus.label",      function(d) { showNodeHover(this, humanizeName(d.artist)); })
                        .on("blur.label",       hideNodeHover)
                        .call(drag(simulation));

                    function showNodeHover(el, name) {
                        $(".node-hover-label").remove();
                        var rect = el.getBoundingClientRect();
                        $("<div/>")
                            .attr("class", "node-hover-label")
                            .text(name)
                            .css({
                                position: "absolute",
                                left: rect.right + window.pageXOffset + 8,
                                top:  rect.top + window.pageYOffset + rect.height / 2 - 10
                            })
                            .appendTo("body");
                    }
                    function hideNodeHover() { $(".node-hover-label").remove(); }

                    // node.attr("r", function(){
                    //     return 5;
                    // });

                    function makeArtistLink(node) {
                        return $("<a/>").attr("href", "#")
                            .attr("class", "artist-link")
                            .attr("id", node.id)
                            .text(humanizeName(node.artist))
                            .bind("click", clickArtistModalLink);
                    }
                    function artistDateString(n) {
                        if (!n) return '';
                        if (n.birth_year && n.death_year) return ' (' + n.birth_year + '–' + n.death_year + ')';
                        if (n.birth_year)                 return ' (b. ' + n.birth_year + ')';
                        if (n.death_year)                 return ' (d. ' + n.death_year + ')';
                        return '';
                    }
                    function dateSuffix(rel) {
                        if (!rel) return '';
                        if (rel.start && rel.end) return ' (' + rel.start + '–' + rel.end + ')';
                        if (rel.start)            return ' (from ' + rel.start + ')';
                        if (rel.end)              return ' (until ' + rel.end + ')';
                        return '';
                    }
                    function sIdOf(l) { return (l.source && l.source.id != null) ? l.source.id : l.source; }
                    function tIdOf(l) { return (l.target && l.target.id != null) ? l.target.id : l.target; }

                    function showModal(e) {
                        if (d3.event && d3.event.stopPropagation) d3.event.stopPropagation();

                        // Remember what to focus when this modal closes so
                        // keyboard users return to their place in the graph.
                        artistModalReturnFocus = d3.event && d3.event.currentTarget ? d3.event.currentTarget : null;

                        var titleId = "artist-modal-title-" + Date.now();
                        var div = $("<div/>")
                            .attr("class", "node-modal")
                            .attr("role", "dialog")
                            .attr("aria-modal", "true")
                            .attr("aria-labelledby", titleId)
                            .attr("tabindex", -1)
                            .css({ "background-color": "white" });

                        var line = $("<div/>").attr("class", "rel-line").attr("id", titleId);
                        var centerUlan = parseInt(ulan, 10);
                        var dateText = '';

                        if (e.group === 0) {
                            // searched artist itself — just the name
                            line.append(makeArtistLink(e));
                        } else {
                            // try direct: center → e
                            var direct = data.links.find(function(l) {
                                return sIdOf(l) === centerUlan && tIdOf(l) === e.id;
                            });
                            if (direct) {
                                line.append(document.createTextNode(direct.relationship + ' '));
                                line.append(makeArtistLink(e));
                                dateText = dateSuffix(direct);
                            } else {
                                // 2-hop: find a 1st-degree intermediary X with center→X and X→e
                                var firstHopByX = {};
                                data.links.forEach(function(l) {
                                    if (sIdOf(l) === centerUlan) firstHopByX[tIdOf(l)] = l;
                                });
                                var inter = null;
                                for (var i = 0; i < data.links.length; i++) {
                                    var l = data.links[i];
                                    if (tIdOf(l) === e.id && firstHopByX[sIdOf(l)]) {
                                        inter = { x: sIdOf(l), relCenter: firstHopByX[sIdOf(l)], relInter: l };
                                        break;
                                    }
                                }
                                if (inter) {
                                    var xNode = data.nodes.find(function(n) { return n.id === inter.x; });
                                    line.append(document.createTextNode(inter.relCenter.relationship + ' '));
                                    if (xNode) line.append(makeArtistLink(xNode));
                                    line.append(document.createTextNode(', who was ' + inter.relInter.relationship + ' '));
                                    line.append(makeArtistLink(e));
                                    dateText = dateSuffix(inter.relInter);
                                } else {
                                    // fallback: any link involving e
                                    var any = data.links.find(function(l) {
                                        return sIdOf(l) === e.id || tIdOf(l) === e.id;
                                    });
                                    if (any && any.relationship) {
                                        line.append(document.createTextNode(any.relationship + ' '));
                                    }
                                    line.append(makeArtistLink(e));
                                    dateText = dateSuffix(any);
                                }
                            }
                        }

                        // portrait placeholder (filled async via Wikidata)
                        var portraitHolder = $("<div/>").attr("class", "modal-portrait-holder").css("display", "none");
                        div.append(portraitHolder);

                        var artistDates = artistDateString(e);
                        if (artistDates) {
                            line.append($("<span/>").attr("class", "artist-dates").text(artistDates));
                        }

                        div.append(line);
                        if (dateText) {
                            $("<p/>").attr("class", "rel-info").text(dateText.trim()).appendTo(div);
                        }

                        // artwork strip (Met Open Access)
                        var artHolder = $("<div/>").attr("class", "modal-artworks").css("display", "none");
                        div.append(artHolder);

                        $.ajax({ url: "portrait.php", data: { ulan: e.id }, dataType: "json", global: false }).done(function(p) {
                            if (p && p.image) {
                                var thumb = p.image + (p.image.indexOf("?") === -1 ? "?width=160" : "&width=160");
                                portraitHolder
                                    .empty()
                                    .append($("<img/>").attr("src", thumb).attr("class", "modal-portrait").attr("alt", e.artist))
                                    .css("display", "block");
                            }
                        });

                        $.ajax({ url: "artworks.php", data: { ulan: e.id, limit: 3 }, dataType: "json", global: false }).done(function(a) {
                            if (!a || !a.works || !a.works.length) return;
                            artHolder.empty().css("display", "flex");
                            a.works.forEach(function(w) {
                                var img = $("<img/>")
                                    .attr("src", w.image)
                                    .attr("alt", w.title || "")
                                    .attr("class", "modal-artwork");
                                $("<a/>")
                                    .attr("href", w.link)
                                    .attr("target", "_blank")
                                    .attr("rel", "noopener")
                                    .attr("title", (w.title || "") + (w.date ? " (" + w.date + ")" : "") + " — The Met")
                                    .append(img)
                                    .appendTo(artHolder);
                            });
                        });

                        var closeBtn = $("<button/>")
                            .attr("type", "button")
                            .attr("aria-label", "Close artist details")
                            .attr("class", "close-modal")
                            .html("&times;")
                            .on("click", function(e) { e.preventDefault(); closeModal.call(this); });

                        div.append(closeBtn);

                        $(".node-modal").remove();
                        // stage off-screen so measurement can't widen the document
                        div.css({ top: -9999, left: -9999 });
                        $("body").append(div);

                        // Position anchored to the clicked circle's on-page rect
                        // so it tracks the node regardless of viewBox scaling.
                        var rect = d3.event.currentTarget.getBoundingClientRect();
                        var modalEl = div[0];
                        var mw = modalEl.offsetWidth;
                        var mh = modalEl.offsetHeight;
                        var nodeCenterX = rect.left + rect.width / 2 + window.pageXOffset;
                        var nodeTop     = rect.top + window.pageYOffset;
                        var gap = 8;
                        var top  = nodeTop - mh - gap;
                        var below = false;
                        if (top < window.pageYOffset + 4) { top = nodeTop + rect.height + gap; below = true; }
                        var left = nodeCenterX - mw / 2;
                        var maxLeft = window.pageXOffset + document.documentElement.clientWidth - mw - 4;
                        if (left < window.pageXOffset + 4) left = window.pageXOffset + 4;
                        if (left > maxLeft) left = maxLeft;
                        div.css({ top: top, left: left, visibility: "visible" });

                        // Move focus into the dialog; keyboard users land here.
                        modalEl.focus();

                        // Esc closes; Tab/Shift-Tab is trapped within the dialog.
                        $(document).off("keydown.artistModal").on("keydown.artistModal", function(ev) {
                            if (ev.key === "Escape" || ev.key === "Esc") {
                                ev.preventDefault(); ev.stopPropagation();
                                closeModal.call(modalEl);
                                return;
                            }
                            if (ev.key !== "Tab") return;
                            var focusables = div.find('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])').toArray();
                            if (!focusables.length) return;
                            var first = focusables[0], last = focusables[focusables.length - 1];
                            var active = document.activeElement;
                            if (ev.shiftKey && (active === first || active === modalEl)) {
                                ev.preventDefault(); last.focus();
                            } else if (!ev.shiftKey && active === last) {
                                ev.preventDefault(); first.focus();
                            }
                        });
                    }

                    function tickRender() {
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
                    }

                    // Respect prefers-reduced-motion: settle the simulation
                    // synchronously and render a single static frame.
                    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    if (reducedMotion) {
                        for (var ti = 0; ti < 300; ti++) simulation.tick();
                        simulation.stop();
                        tickRender();
                    } else {
                        simulation.on("tick", tickRender);
                    }

                    return svg.node();
                }

                if (/Mobi|Android/i.test(navigator.userAgent)) {
                    // portrait viewBox so vertical space isn't wasted on phones
                    width  = 420;
                    height = 720;
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

                var renderChart = function() {
                    var mode = $("#layout-toggle .active").attr("data-layout") || "grid";
                    $("#mobile-stage").empty().append(chart(mode));
                    $("#stage").empty().append(chart(mode));
                };
                // sync URL + search box label to the loaded artist
                var centerNode = data.nodes.find(function(n) { return n.group === 0; });
                if (centerNode) {
                    $("#hint").val(humanizeName(centerNode.artist));
                    if (!inHistoryNav) syncUrlToUlan(ulan, false);
                }
                inHistoryNav = false;

                // refresh the side bio + relationships panel for the loaded artist
                loadArtistBioPanel(ulan);

                renderChart();
                $("#rel-legend").show();
                $("#layout-toggle").show().off("click.layout").on("click.layout", "button[data-layout]", function() {
                    $("#layout-toggle button[data-layout]").removeClass("active");
                    $(this).addClass("active");
                    renderChart();
                });
                $("#toggle-depth").off("click.depth").on("click.depth", function() {
                    $(this).toggleClass("active");
                    $("form#searchNetworks").submit();
                });

            }
        });

    });


    function showBaconModal(node, eventTarget) {
        if (d3.event && d3.event.stopPropagation) d3.event.stopPropagation();

        artistModalReturnFocus = eventTarget || null;
        var titleId = "bacon-modal-title-" + Date.now();
        var div = $("<div/>")
            .attr("class", "node-modal")
            .attr("role", "dialog")
            .attr("aria-modal", "true")
            .attr("aria-labelledby", titleId)
            .attr("tabindex", -1)
            .css({ "background-color": "white" });
        var portraitHolder = $("<div/>").attr("class", "modal-portrait-holder").css("display", "none").appendTo(div);
        var artHolder = $("<div/>").attr("class", "modal-artworks").css("display", "none");
        var line = $("<div/>").attr("class", "rel-line").attr("id", titleId).appendTo(div);
        line.append(
            $("<a/>").attr("href", "/?ulan=" + node.id)
                     .attr("class", "artist-link")
                     .attr("id", "bacon-link-" + node.id)
                     .text(humanizeName(node.artist))
        );
        var dates = '';
        if (node.birth_year && node.death_year) dates = ' (' + node.birth_year + '–' + node.death_year + ')';
        else if (node.birth_year)               dates = ' (b. ' + node.birth_year + ')';
        else if (node.death_year)               dates = ' (d. ' + node.death_year + ')';
        if (dates) line.append($("<span/>").attr("class", "artist-dates").text(dates));
        div.append(artHolder);
        $("<button/>").attr("type", "button").attr("aria-label", "Close artist details")
            .attr("class", "close-modal").html("&times;")
            .on("click", function(e) { e.preventDefault(); closeModal(); })
            .appendTo(div);

        $(".node-modal").remove();
        div.css({ visibility: "hidden", top: 0, left: 0 });
        $("body").append(div);

        $.ajax({ url: "portrait.php", data: { ulan: node.id }, dataType: "json", global: false }).done(function(p) {
            if (p && p.image) {
                var thumb = p.image + (p.image.indexOf("?") === -1 ? "?width=160" : "&width=160");
                portraitHolder.empty()
                    .append($("<img/>").attr("src", thumb).attr("class", "modal-portrait").attr("alt", node.artist))
                    .css("display", "block");
            }
        });

        $.ajax({ url: "artworks.php", data: { ulan: node.id, limit: 3 }, dataType: "json", global: false }).done(function(a) {
            if (!a || !a.works || !a.works.length) return;
            artHolder.empty().css("display", "flex");
            a.works.forEach(function(w) {
                $("<a/>").attr("href", w.link).attr("target", "_blank").attr("rel", "noopener")
                    .attr("title", (w.title || "") + (w.date ? " (" + w.date + ")" : "") + " — The Met")
                    .append($("<img/>").attr("src", w.image).attr("alt", w.title || "").attr("class", "modal-artwork"))
                    .appendTo(artHolder);
            });
        });

        // bio + relationships (mirrors the #bio panel on the main viz)
        var bioHolder = $("<div/>").attr("class", "modal-bio-section").css("display", "none").appendTo(div);
        $.ajax({ url: "data.php", dataType: "text", global: false, data: { note: node.id } }).done(function(raw) {
            try {
                var info = JSON.parse(raw);
                if (!info.bio && !(info.rels && info.rels.length)) return;
                bioHolder.css("display", "block");
                if (info.bio) {
                    $("<p/>").attr("class", "modal-bio").text(info.bio).appendTo(bioHolder);
                }
                if (info.rels && info.rels.length) {
                    $("<p/>").attr("class", "modal-rels-title").text("Connections").appendTo(bioHolder);
                    var ul = $("<ul/>").attr("class", "modal-rels-list");
                    info.rels.slice(0, 12).forEach(function(html) {
                        var li = $("<li/>").html(html);
                        li.children("a.artist-link").attr("href", function() {
                            return "/?ulan=" + $(this).attr("id");
                        });
                        ul.append(li);
                    });
                    if (info.rels.length > 12) {
                        ul.append($("<li/>").attr("class", "modal-rels-more")
                            .text("…and " + (info.rels.length - 12) + " more"));
                    }
                    bioHolder.append(ul);
                }
            } catch(e) {}
        });

        var rect = eventTarget.getBoundingClientRect();
        var modalEl = div[0];
        var mw = modalEl.offsetWidth;
        var mh = modalEl.offsetHeight;
        var nodeCenterX = rect.left + rect.width / 2 + window.pageXOffset;
        var nodeTop = rect.top + window.pageYOffset;
        var gap = 8;
        var top = nodeTop - mh - gap;
        if (top < window.pageYOffset + 4) top = nodeTop + rect.height + gap;
        var left = nodeCenterX - mw / 2;
        var maxLeft = window.pageXOffset + document.documentElement.clientWidth - mw - 4;
        if (left < window.pageXOffset + 4) left = window.pageXOffset + 4;
        if (left > maxLeft) left = maxLeft;
        div.css({ top: top, left: left, visibility: "visible" });

        var modalElB = div[0];
        modalElB.focus();
        $(document).off("keydown.artistModal").on("keydown.artistModal", function(ev) {
            if (ev.key === "Escape" || ev.key === "Esc") {
                ev.preventDefault(); ev.stopPropagation();
                closeModal();
                return;
            }
            if (ev.key !== "Tab") return;
            var focusables = div.find('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])').toArray();
            if (!focusables.length) return;
            var first = focusables[0], last = focusables[focusables.length - 1];
            var active = document.activeElement;
            if (ev.shiftKey && (active === first || active === modalElB)) {
                ev.preventDefault(); last.focus();
            } else if (!ev.shiftKey && active === last) {
                ev.preventDefault(); first.focus();
            }
        });
    }

    // Snap every label group back to its anchor and hide leader lines.
    function resetLabelPositions(svgNode) {
        if (!svgNode) return;
        var groups = svgNode.querySelectorAll('g[data-kind]');
        groups.forEach(function(g) {
            var ax = parseFloat(g.getAttribute('data-anchor-x'));
            var ay = parseFloat(g.getAttribute('data-anchor-y'));
            if (!isNaN(ax) && !isNaN(ay)) {
                g.setAttribute('transform', 'translate(' + ax + ',' + ay + ')');
            }
            var leader = g.querySelector('line.leader');
            if (leader) {
                leader.setAttribute('x2', '0');
                leader.setAttribute('y2', '0');
                leader.setAttribute('opacity', '0');
            }
        });
    }

    // After an SVG is in the DOM, nudge overlapping label *groups* apart so
    // each node's name/tag/year stays bundled and edge labels stay tied to
    // their edge (via a leader line if they get pushed off the midpoint).
    function resolveLabelOverlaps(svgNode) {
        if (!svgNode) return;
        var groups  = Array.prototype.slice.call(svgNode.querySelectorAll('g[data-kind]'));
        var circles = Array.prototype.slice.call(svgNode.querySelectorAll('circle.node'));
        if (!groups.length) return;
        // edgeLabel moves first; nodeLabel only moves when nothing else works.
        var prio = { edgeLabel: 0, nodeLabel: 1 };
        var pad  = 3;

        function getTx(g) {
            var t = g.getAttribute('transform') || '';
            var m = /translate\(([-\d.]+)[, ]+([-\d.]+)\)/.exec(t);
            return m ? [parseFloat(m[1]), parseFloat(m[2])] : [0, 0];
        }
        function setTx(g, x, y) { g.setAttribute('transform', 'translate(' + x + ',' + y + ')'); }

        function groupRect(g) {
            var b = g.getBBox();
            var t = getTx(g);
            return { x: b.x + t[0] - pad, y: b.y + t[1] - pad, w: b.width + 2*pad, h: b.height + 2*pad };
        }
        function circleRect(c) {
            var cx = parseFloat(c.getAttribute('cx'));
            var cy = parseFloat(c.getAttribute('cy'));
            var r  = parseFloat(c.getAttribute('r')) + pad;
            return { x: cx - r, y: cy - r, w: 2*r, h: 2*r, fixed: true };
        }
        function overlap(a, b) {
            var dx = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
            var dy = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
            if (dx <= 0 || dy <= 0) return null;
            return { dx: dx, dy: dy };
        }
        function updateLeader(g) {
            if (g.dataset.kind !== 'edgeLabel') return;
            var leader = g.querySelector('line.leader');
            if (!leader) return;
            var ax = parseFloat(g.getAttribute('data-anchor-x'));
            var ay = parseFloat(g.getAttribute('data-anchor-y'));
            var t  = getTx(g);
            var lx = ax - t[0], ly = ay - t[1];
            var dist = Math.sqrt(lx*lx + ly*ly);
            if (dist > 6) {
                leader.setAttribute('x2', lx.toString());
                leader.setAttribute('y2', ly.toString());
                leader.setAttribute('opacity', '0.55');
            } else {
                leader.setAttribute('opacity', '0');
            }
        }

        // Build the obstacle list: groups (movable) + circles (fixed)
        var items = groups.map(function(g) { return { el: g, kind: g.dataset.kind, rect: groupRect(g), fixed: false }; });
        circles.forEach(function(c) { items.push({ el: c, kind: 'circle', rect: circleRect(c), fixed: true }); });

        for (var pass = 0; pass < 12; pass++) {
            var moved = false;
            for (var i = 0; i < items.length; i++) {
                for (var j = i + 1; j < items.length; j++) {
                    var ov = overlap(items[i].rect, items[j].rect);
                    if (!ov) continue;
                    var a = items[i], b = items[j];
                    if (a.fixed && b.fixed) continue;
                    var movItem, othItem;
                    if (a.fixed) { movItem = b; othItem = a; }
                    else if (b.fixed) { movItem = a; othItem = b; }
                    else {
                        // both movable: pick by priority (lower prio moves)
                        if (prio[a.kind] <= prio[b.kind]) { movItem = a; othItem = b; }
                        else                              { movItem = b; othItem = a; }
                    }
                    var rm = movItem.rect, ro = othItem.rect;
                    var cmx = rm.x + rm.w/2, cmy = rm.y + rm.h/2;
                    var cox = ro.x + ro.w/2, coy = ro.y + ro.h/2;
                    var dx = 0, dy = 0;
                    if (ov.dy <= ov.dx) {
                        dy = (cmy >= coy ? 1 : -1) * (ov.dy + 1);
                    } else {
                        dx = (cmx >= cox ? 1 : -1) * (ov.dx + 1);
                    }
                    var t = getTx(movItem.el);
                    setTx(movItem.el, t[0] + dx, t[1] + dy);
                    rm.x += dx; rm.y += dy;
                    updateLeader(movItem.el);
                    moved = true;
                }
            }
            if (!moved) break;
        }
    }

    function buildBaconDAG(paths, w, h, vertical) {
        var linkColor = {
            mentorship:    "#5b9bd5",
            family:        "#e57373",
            affiliation:   "#81c784",
            collaboration: "#ba68c8",
            other:         "#888"
        };

        // merge all paths into a single graph
        var nodeMap = {};
        var linkMap = {};
        var nodes = [];
        var links = [];
        paths.forEach(function(p) {
            p.nodes.forEach(function(n) {
                if (!nodeMap[n.id]) {
                    nodeMap[n.id] = $.extend({}, n);
                    nodes.push(nodeMap[n.id]);
                }
            });
            p.links.forEach(function(l) {
                var key = l.source + "-" + l.target;
                if (!linkMap[key]) {
                    linkMap[key] = $.extend({}, l);
                    links.push(linkMap[key]);
                }
            });
        });

        // group nodes by depth (group = hop from source)
        var byDepth = {};
        var maxDepth = 0;
        nodes.forEach(function(n) {
            (byDepth[n.group] = byDepth[n.group] || []).push(n);
            if (n.group > maxDepth) maxDepth = n.group;
        });

        var padMain = 60;
        var span    = (vertical ? h : w) - 2 * padMain;
        var center  = (vertical ? w : h) / 2;
        function stepFor(d) {
            return maxDepth === 0 ? padMain + span / 2 : padMain + d * span / maxDepth;
        }

        // assign positions
        Object.keys(byDepth).forEach(function(dKey) {
            var col = byDepth[dKey];
            var d   = parseInt(dKey, 10);
            var sPos = stepFor(d);
            if (col.length === 1) {
                // single-node column: zigzag by depth parity for long chains
                var zigOn = !vertical && maxDepth >= 5;
                var zigOff = zigOn ? Math.min(80, (h - 240) / 2) : 0;
                var up = zigOn && (d % 2 === 0);
                var cPos = center + (zigOn ? (up ? -zigOff : zigOff) : 0);
                if (vertical) { col[0].x = cPos; col[0].y = sPos; }
                else          { col[0].x = sPos; col[0].y = cPos; }
                col[0].labelDir = up ? -1 : 1;
            } else {
                // multi-node column: spread perpendicular to the main axis
                var spread = Math.min(110, ((vertical ? w : h) - 100) / col.length);
                col.forEach(function(n, idx) {
                    var t = idx - (col.length - 1) / 2;
                    var cPos = center + t * spread;
                    if (vertical) { n.x = cPos; n.y = sPos; }
                    else          { n.x = sPos; n.y = cPos; }
                    // top half labels above, bottom half below
                    n.labelDir = (idx < col.length / 2) ? -1 : 1;
                });
            }
        });

        var svg = d3.create("svg")
            .attr("viewBox", [0, 0, w, h])
            .attr("role", "img")
            .attr("aria-label",
                "Path between artists with " + nodes.length + " hops. Use Tab to step through artists and Enter to open details.");

        // draw edges + edge-label groups (with hidden leader line)
        var edgeG  = svg.append("g");
        var labelG = svg.append("g");
        links.forEach(function(l) {
            var s = nodeMap[l.source];
            var t = nodeMap[l.target];
            if (!s || !t) return;
            edgeG.append("line")
                .attr("x1", s.x).attr("x2", t.x)
                .attr("y1", s.y).attr("y2", t.y)
                .attr("stroke", linkColor[l.category] || linkColor.other)
                .attr("stroke-width", 2)
                .attr("stroke-opacity", 0.85);
            var midX = (s.x + t.x) / 2;
            var midY = (s.y + t.y) / 2;
            var elg = labelG.append("g")
                .attr("data-kind", "edgeLabel")
                .attr("data-anchor-x", midX)
                .attr("data-anchor-y", midY)
                .attr("transform", "translate(" + midX + "," + midY + ")");
            elg.append("line")
                .attr("class", "leader")
                .attr("x1", 0).attr("y1", 0)
                .attr("x2", 0).attr("y2", 0)
                .attr("stroke", "#666").attr("stroke-width", 1)
                .attr("stroke-dasharray", "2,2")
                .attr("opacity", 0);
            elg.append("text")
                .attr("class", "label-edge")
                .attr("x", 0).attr("y", -4)
                .attr("text-anchor", "middle")
                .attr("fill", "#ddd").attr("font-size", 11)
                .attr("font-style", "italic")
                .text(l.relationship || "connected to");
        });

        // draw nodes + node-label groups (name+tag+year bundled as one unit)
        var nodeG = svg.append("g");
        nodes.forEach(function(n) {
            var isEnd = n.source || n.target;
            var r = isEnd ? 14 : 9;
            nodeG.append("circle")
                .datum(n)
                .attr("cx", n.x).attr("cy", n.y).attr("r", r)
                .attr("fill", isEnd ? "white" : "#222")
                .attr("stroke", "white").attr("stroke-width", 2)
                .attr("class", "node bacon")
                .style("cursor", "pointer")
                .attr("tabindex", 0)
                .attr("role", "button")
                .attr("aria-label", humanizeName(n.artist) + (isEnd ? " (endpoint)" : " (intermediate hop)"))
                .on("click", function(d) { showBaconModal(d, this); })
                .on("keydown", function(d) {
                    var k = d3.event.key;
                    if (k === "Enter" || k === " " || k === "Spacebar") {
                        d3.event.preventDefault();
                        showBaconModal(d, this);
                    }
                })
                .on("focus.label", function(d) {
                    $(".node-hover-label").remove();
                    var rect = this.getBoundingClientRect();
                    $("<div/>").attr("class", "node-hover-label")
                        .text(humanizeName(d.artist))
                        .css({ position: "absolute",
                               left: rect.right + window.pageXOffset + 8,
                               top:  rect.top + window.pageYOffset + rect.height / 2 - 10 })
                        .appendTo("body");
                })
                .on("blur.label", function() { $(".node-hover-label").remove(); })
                .on("mouseenter.label", function(d) {
                    var rect = this.getBoundingClientRect();
                    $("<div/>").attr("class", "node-hover-label")
                        .text(humanizeName(d.artist))
                        .css({ position: "absolute",
                               left: rect.right + window.pageXOffset + 8,
                               top:  rect.top + window.pageYOffset + rect.height / 2 - 10 })
                        .appendTo("body");
                })
                .on("mouseleave.label", function() { $(".node-hover-label").remove(); });

            var dir = n.labelDir || 1;
            var step = 14;
            var ly = dir * (r + 14);
            var nlg = labelG.append("g")
                .attr("data-kind", "nodeLabel")
                .attr("data-anchor-x", n.x)
                .attr("data-anchor-y", n.y)
                .attr("transform", "translate(" + n.x + "," + n.y + ")");

            var tag = n.source ? "FROM" : (n.target ? "TO" : null);
            if (tag) {
                nlg.append("text")
                    .attr("class", "label-tag")
                    .attr("x", 0).attr("y", ly)
                    .attr("text-anchor", "middle")
                    .attr("fill", "#999").attr("font-size", 11)
                    .attr("letter-spacing", "1px")
                    .text(tag);
                ly += dir * step;
            }
            nlg.append("text")
                .attr("class", "label-name")
                .attr("x", 0).attr("y", ly)
                .attr("text-anchor", "middle")
                .attr("fill", "white").attr("font-size", 12)
                .text(humanizeName(n.artist));
            ly += dir * step;
            if (n.birth_year) {
                nlg.append("text")
                    .attr("class", "label-year")
                    .attr("x", 0).attr("y", ly)
                    .attr("text-anchor", "middle")
                    .attr("fill", "#888").attr("font-size", 10)
                    .text("b. " + n.birth_year);
            }
        });

        return svg.node();
    }

    function buildBaconList(data) {
        var linkColor = {
            mentorship:    "#5b9bd5",
            family:        "#e57373",
            affiliation:   "#81c784",
            collaboration: "#ba68c8",
            other:         "#888"
        };
        var nodeById = {};
        data.nodes.forEach(function(n) { nodeById[n.id] = n; });
        var list = $("<ol/>").attr("class", "bacon-list");
        data.links.forEach(function(l) {
            var s = nodeById[l.source];
            var t = nodeById[l.target];
            if (!s || !t) return;
            var li = $("<li/>");
            li.append(
                $("<a/>").attr("href", "/?ulan=" + s.id)
                         .attr("class", "artist-link")
                         .attr("id", s.id)
                         .text(humanizeName(s.artist))
            );
            var dateText = "";
            if (l.start && l.end) dateText = " (" + l.start + "–" + l.end + ")";
            else if (l.start)     dateText = " (from " + l.start + ")";
            else if (l.end)       dateText = " (until " + l.end + ")";
            var relSpan = $("<span/>")
                .attr("class", "bacon-list-rel")
                .text(" " + (l.relationship || "connected to") + dateText + " ")
                .css("color", linkColor[l.category] || linkColor.other);
            li.append(relSpan);
            li.append(
                $("<a/>").attr("href", "/?ulan=" + t.id)
                         .attr("class", "artist-link")
                         .attr("id", t.id)
                         .text(humanizeName(t.artist))
            );
            list.append(li);
        });
        return list;
    }

    function buildBaconChain(data, w, h, vertical) {
        var linkColor = {
            mentorship:    "#5b9bd5",
            family:        "#e57373",
            affiliation:   "#81c784",
            collaboration: "#ba68c8",
            other:         "#888"
        };
        var nodes = data.nodes;
        var links = data.links;
        var N = nodes.length;
        var nodeById = {};
        nodes.forEach(function(n) { nodeById[n.id] = n; });

        var padMain = 60;
        var crossPos = vertical ? w / 2 : h / 2;
        var spanLen = (vertical ? h : w) - 2 * padMain;
        function stepFor(i) {
            return N === 1 ? padMain + spanLen / 2 : padMain + i * spanLen / (N - 1);
        }
        // Zig-zag rows for long horizontal chains so node labels don't overlap.
        var zigEnabled = !vertical && N >= 6;
        var zigOffset  = Math.min(80, (h - 240) / 2);
        nodes.forEach(function(n) {
            var s = stepFor(n.group);
            if (vertical) {
                n.x = crossPos; n.y = s;
                n.labelDir = 1; // labels below
            } else if (zigEnabled) {
                var up = (n.group % 2 === 0);
                n.x = s;
                n.y = crossPos + (up ? -zigOffset : zigOffset);
                n.labelDir = up ? -1 : 1;
            } else {
                n.x = s; n.y = crossPos;
                n.labelDir = 1;
            }
        });

        var svg = d3.create("svg")
            .attr("viewBox", [0, 0, w, h])
            .attr("role", "img")
            .attr("aria-label",
                "Network of " + nodes.length + " artists across all shortest paths. Use Tab to step through artists and Enter to open details.");

        var edgeG  = svg.append("g");
        var labelG = svg.append("g");
        links.forEach(function(l) {
            var s = nodeById[l.source];
            var t = nodeById[l.target];
            if (!s || !t) return;
            edgeG.append("line")
                .attr("x1", s.x).attr("x2", t.x)
                .attr("y1", s.y).attr("y2", t.y)
                .attr("stroke", linkColor[l.category] || linkColor.other)
                .attr("stroke-width", 2);
            var midX = (s.x + t.x) / 2;
            var midY = (s.y + t.y) / 2;
            var label = (l.relationship || 'connected to');
            if (!zigEnabled) {
                if (l.start && l.end)      label += ' (' + l.start + '–' + l.end + ')';
                else if (l.start)          label += ' (from ' + l.start + ')';
                else if (l.end)            label += ' (until ' + l.end + ')';
            }
            var fontSize = zigEnabled ? 11 : 13;
            var elg = labelG.append("g")
                .attr("data-kind", "edgeLabel")
                .attr("data-anchor-x", midX)
                .attr("data-anchor-y", midY)
                .attr("transform", "translate(" + midX + "," + midY + ")");
            elg.append("line")
                .attr("class", "leader")
                .attr("x1", 0).attr("y1", 0)
                .attr("x2", 0).attr("y2", 0)
                .attr("stroke", "#666").attr("stroke-width", 1)
                .attr("stroke-dasharray", "2,2")
                .attr("opacity", 0);
            elg.append("text")
                .attr("class", "label-edge")
                .attr("x", vertical ? 14 : 0)
                .attr("y", vertical ? 4 : -6)
                .attr("text-anchor", vertical ? "start" : "middle")
                .attr("fill", "#ddd")
                .attr("font-size", fontSize)
                .attr("font-style", "italic")
                .text(label);
        });

        var nodeG = svg.append("g");
        nodes.forEach(function(n) {
            var isEnd = n.source || n.target;
            var r = isEnd ? 14 : 9;
            nodeG.append("circle")
                .datum(n)
                .attr("cx", n.x).attr("cy", n.y).attr("r", r)
                .attr("fill", isEnd ? "white" : "#222")
                .attr("stroke", "white").attr("stroke-width", 2)
                .attr("class", "node bacon")
                .style("cursor", "pointer")
                .attr("tabindex", 0)
                .attr("role", "button")
                .attr("aria-label", humanizeName(n.artist) + (isEnd ? " (endpoint)" : " (intermediate hop)"))
                .on("click", function(d) { showBaconModal(d, this); })
                .on("keydown", function(d) {
                    var k = d3.event.key;
                    if (k === "Enter" || k === " " || k === "Spacebar") {
                        d3.event.preventDefault();
                        showBaconModal(d, this);
                    }
                })
                .on("focus.label", function(d) {
                    $(".node-hover-label").remove();
                    var rect = this.getBoundingClientRect();
                    $("<div/>").attr("class", "node-hover-label")
                        .text(humanizeName(d.artist))
                        .css({ position: "absolute",
                               left: rect.right + window.pageXOffset + 8,
                               top:  rect.top + window.pageYOffset + rect.height / 2 - 10 })
                        .appendTo("body");
                })
                .on("blur.label", function() { $(".node-hover-label").remove(); })
                .on("mouseenter.label", function(d) {
                    var rect = this.getBoundingClientRect();
                    $("<div/>").attr("class", "node-hover-label")
                        .text(humanizeName(d.artist))
                        .css({ position: "absolute",
                               left: rect.right + window.pageXOffset + 8,
                               top:  rect.top + window.pageYOffset + rect.height / 2 - 10 })
                        .appendTo("body");
                })
                .on("mouseleave.label", function() { $(".node-hover-label").remove(); });

            var dir = n.labelDir || 1;
            var step = 14;
            var ly = dir * (r + 14);
            var nlg = labelG.append("g")
                .attr("data-kind", "nodeLabel")
                .attr("data-anchor-x", n.x)
                .attr("data-anchor-y", n.y)
                .attr("transform", "translate(" + n.x + "," + n.y + ")");

            var tag = n.source ? "FROM" : (n.target ? "TO" : null);
            if (tag) {
                nlg.append("text")
                    .attr("class", "label-tag")
                    .attr("x", 0).attr("y", ly)
                    .attr("text-anchor", "middle")
                    .attr("fill", "#999").attr("font-size", 11)
                    .attr("letter-spacing", "1px")
                    .text(tag);
                ly += dir * step;
            }
            nlg.append("text")
                .attr("class", "label-name")
                .attr("x", 0).attr("y", ly)
                .attr("text-anchor", "middle")
                .attr("fill", "white").attr("font-size", 12)
                .text(humanizeName(n.artist));
            ly += dir * step;
            if (n.birth_year) {
                nlg.append("text")
                    .attr("class", "label-year")
                    .attr("x", 0).attr("y", ly)
                    .attr("text-anchor", "middle")
                    .attr("fill", "#888").attr("font-size", 10)
                    .text("b. " + n.birth_year);
            }
        });

        return svg.node();
    }

    function syncBaconUrl(u1, u2) {
        var url = window.location.pathname + '?ulan1=' + u1 + '&ulan2=' + u2;
        history.replaceState({ ulan1: u1, ulan2: u2 }, '', url);
    }

    // fetch data and build bacon visualization
    $("form#baconForm").submit(function (e) {
        e.preventDefault();
        var ulan1 = $("#searchUlan1").val();
        var ulan2 = $("#searchUlan2").val();
        if (!ulan1 || !ulan2) {
            $("#bacon-stage").html('<div class="nobacon"><p>Please select both artists.</p></div>');
            return;
        }
        syncBaconUrl(ulan1, ulan2);

        $("#bacon-stage").html('<p class="bacon-loading">Searching for the shortest path…</p>');

        $.ajax({
            url: "data.php",
            dataType: "json",
            global: false,
            data: { bacon: 1, ulan1: ulan1, ulan2: ulan2 },
            success: function(data) {
                var isMobile = /Mobi|Android/i.test(navigator.userAgent);
                var bw, bh, vertical;
                if (isMobile) { bw = 420; bh = 720; vertical = true; }
                else          { bw = 1000; bh = 500; vertical = false; }

                // For dense multi-path views, give labels more vertical room.
                if (!vertical && (data.paths || []).length > 1) {
                    var hops = ((data.paths[0] || {}).nodes || []).length - 1;
                    var perDepth = {};
                    data.paths.forEach(function(p) {
                        p.nodes.forEach(function(n) {
                            perDepth[n.group] = perDepth[n.group] || {};
                            perDepth[n.group][n.id] = 1;
                        });
                    });
                    var maxCol = 0;
                    for (var k in perDepth) {
                        var c = Object.keys(perDepth[k]).length;
                        if (c > maxCol) maxCol = c;
                    }
                    if (hops >= 6 || maxCol >= 3) bh = 680;
                }

                $("#bacon-stage").empty();
                $("#bacon-controls").prop("hidden", true);

                var paths = data.paths || [];
                if (!paths.length || !paths[0].nodes.length) {
                    $("#bacon-stage").html('<div class="nobacon"><p>No path could be found between these two artists in the current data.</p></div>');
                    return;
                }
                $("#bacon-controls").prop("hidden", false);

                // populate the input boxes from the first path's endpoints
                var firstNodes = paths[0].nodes;
                var src = firstNodes.find(function(n) { return n.source; });
                var tgt = firstNodes.find(function(n) { return n.target; });
                if (src) $("#bacon-hint1").val(humanizeName(src.artist));
                if (tgt) $("#bacon-hint2").val(humanizeName(tgt.artist));

                if (paths.length === 1) {
                    var chainSvg = buildBaconChain(paths[0], bw, bh, vertical);
                    $("#bacon-stage").append(chainSvg);
                    resolveLabelOverlaps(chainSvg);
                    $("#bacon-stage").append(buildBaconList(paths[0]));
                } else {
                    var hopCount = paths[0].nodes.length - 1;
                    $("<h3/>").attr("class", "bacon-path-header")
                        .text(paths.length + " shortest paths — " + hopCount + " hops")
                        .appendTo("#bacon-stage");
                    var dagSvg = buildBaconDAG(paths, bw, bh, vertical);
                    $("#bacon-stage").append(dagSvg);
                    resolveLabelOverlaps(dagSvg);
                    paths.forEach(function(pathData, idx) {
                        var wrap = $("<div/>").attr("class", "bacon-path-wrap");
                        $("<h3/>").attr("class", "bacon-path-header")
                            .text("Path " + (idx + 1) + " of " + paths.length)
                            .appendTo(wrap);
                        wrap.append(buildBaconList(pathData));
                        $("#bacon-stage").append(wrap);
                    });
                }
            },
            error: function() {
                $("#bacon-stage").html('<div class="nobacon"><p>Search failed — please try again.</p></div>');
            }
        });

    });

    // Bacon label toggles — flip a class on #bacon-stage and re-run the
    // collision resolver so visible labels can reclaim freed space.
    $("#bacon-controls").on("change", "input[data-toggle]", function() {
        var kind = $(this).data("toggle");
        var on   = this.checked;
        $("#bacon-stage").toggleClass("hide-" + kind, !on);
        var svg = $("#bacon-stage svg")[0];
        if (svg) {
            resetLabelPositions(svg);
            resolveLabelOverlaps(svg);
        }
    });

    // Auto-load bacon from ?ulan1=X&ulan2=Y if both present.
    var bp = new URLSearchParams(window.location.search);
    var bu1 = bp.get('ulan1'), bu2 = bp.get('ulan2');
    if (bu1 && bu2 && $("form#baconForm").length) {
        $("#searchUlan1").val(bu1);
        $("#searchUlan2").val(bu2);
        $("form#baconForm").submit();
    }

    $("#bacon-hint1, #bacon-hint2").autocomplete({

        minLength: 4,
        delay: 500,

        source: function( request, response ) {
            var inputID = $(this.element).prop("id");
            $("#suggestion-results").empty();
            $.ajax({
                url: "data.php",
                dataType: "json",
                global: false,
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
                            .text(humanizeName(value[0]))
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

    // Auto-load from ?ulan=… on page open so URLs are shareable.
    var initialUlan = urlUlan();
    if (initialUlan) {
        inHistoryNav = true;
        $("#searchUlan").val(initialUlan);
        if (urlDepth() === 2) $("#toggle-depth").addClass("active");
        history.replaceState({ ulan: initialUlan, depth: urlDepth() }, '', window.location.search);
        $("form#searchNetworks").submit();
    }

});   