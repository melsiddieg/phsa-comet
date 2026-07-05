function openFilterNav() 
{
	document.getElementById("FilterSideNav").style.width = "350px";
}

function closeFilterNav() 
{
	document.getElementById("FilterSideNav").style.width = "0";
}


function open_mr_edit(data_id) 
{
	document.getElementById("mr_edit_main_div").style.width = "100%";
  
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id);
}

function close_mr_edit() 
{
	document.getElementById("mr_edit_main_div").style.width = "0%";
}

function open_review_edit(data_id) 
{
	document.getElementById("review_edit_main_div").style.width = "100%";
  
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id);
}

function close_review_edit() 
{
	document.getElementById("review_edit_main_div").style.width = "0%";
}

function submit_add(data_id) 
{
	if( $("#new_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									new_map_code: $("#new_map_code").val(),
									new_map_vocabulary: $("#new_map_vocabulary").val(),
									submit_add : 1
								});
}

function submit_update(data_id, map_id) 
{
	if( $("#update_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									map_id: map_id,
									update_map_code: $("#update_map_code_" + map_id).val(),
									update_map_vocabulary: $("#update_map_vocabulary_" + map_id).val(),
									submit_update : 1
								});
}

function submit_delete(data_id, map_id) 
{
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									map_id: map_id,
									submit_delete : 1
								});
}

function submit_excl_update(data_id) 
{
	var exclude_status = $("input[type=radio][name=exclude_status]:checked").val();
	if ( !exclude_status ) 
	{
		alert('Nothing is selected');
		return false;
	}

	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									exclude_status: exclude_status,
									comment_text: $("#comment_text").val(),
									exclude_status_submit : 1
								});
}








/* ───────────── Concept search panel (edit_mr_item.php) ───────────── */

function esc_html(s)
{
	return String(s == null ? "" : s)
		.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

function esc_js(s)
{
	return String(s == null ? "" : s)
		.replace(/\\/g, "\\\\").replace(/'/g, "\\'").replace(/"/g, "&quot;");
}

function concept_search(sheet_id)
{
	var kw = $("#concept_kw").val().trim();
	if( kw == "" )
		return false;

	$("#concept_results").html("<i>Searching...</i>");

	$.post("search_concept.php",
		{
			kw: kw,
			sheet_id: sheet_id,
			domain: $("#concept_domain").val(),
			vocab: $("#concept_vocab").val(),
			standard_only: $("#concept_std").is(":checked") ? "1" : "0",
			valid_only: $("#concept_valid").is(":checked") ? "1" : "0"
		},
		function(data) { render_concept_results(data); },
		"json"
	).fail(function() {
		$("#concept_results").html("<font color='red'>Search failed - is the OMOP vocabulary loaded?</font>");
	});
}

function render_concept_results(data)
{
	var rows = data.results || [];
	if( rows.length == 0 )
	{
		$("#concept_results").html("No concepts found. Try fewer words, the 'All' vocabulary filter, or unticking 'Standard only'.");
		return;
	}

	var h = "<table border='1' cellspacing='0' cellpadding='3' style='font-size:10pt; background-color:white;'>";
	h += "<tr style='color:white; background-color:#202080;'><td></td><td>Score</td><td>Concept Name</td><td>Code</td><td>Domain</td><td>Vocabulary</td><td>Class</td><td>Matched on</td></tr>";

	for( var i = 0 ; i < rows.length ; i++ )
	{
		var r = rows[i];
		var matched = r.matched_on == "synonym" ? "synonym: " + esc_html(r.synonym_name) : r.matched_on;
		var flag = (r.standard_concept != "S" || r.invalid_reason) ? " <font color='red'>(non-standard)</font>" : "";
		h += "<tr>";
		h += "<td><button onclick=\"pick_concept('" + esc_js(r.concept_code) + "', '" + esc_js(r.vocabulary_id) + "')\">Use</button></td>";
		h += "<td>" + esc_html(r.score) + "</td>";
		h += "<td>" + esc_html(r.concept_name) + flag + "</td>";
		h += "<td>" + esc_html(r.concept_code) + "</td>";
		h += "<td>" + esc_html(r.domain_id) + "</td>";
		h += "<td>" + esc_html(r.vocabulary_id) + "</td>";
		h += "<td>" + esc_html(r.concept_class_id) + "</td>";
		h += "<td>" + matched + "</td>";
		h += "</tr>";
	}
	h += "</table>";

	if( data.vocab_release )
		h += "<div style='font-size:9pt; color:#606060; margin-top:4px;'>Vocabulary release: " + esc_html(data.vocab_release) + "</div>";

	$("#concept_results").html(h);
}

function submit_propagate(data_id, concept_id)
{
	if( !confirm("Apply this target to all identical unmapped terms in this sheet?") )
		return false;

	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id,
								{
									prop_concept_id: concept_id,
									submit_propagate: 1
								});
}

function pick_concept(code, vocab)
{
	$("#new_map_code").val(code);
	$("#new_map_vocabulary").val(vocab);

	// also offer it in the update form of an existing map, if present
	$("input[name='update_map_code']").val(code);
	$("select[name='update_map_vocabulary']").val(vocab);

	$("#new_map_code").css("background-color", "#d0ffd0");
	setTimeout(function() { $("#new_map_code").css("background-color", ""); }, 1200);
}


function submit_add_rev(data_id)
{
	if( $("#new_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									new_map_code: $("#new_map_code").val(),
									new_map_vocabulary: $("#new_map_vocabulary").val(),
									submit_add : 1
								});
}

function submit_update_rev(data_id, map_id) 
{
	if( $("#update_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									map_id: map_id,
									update_map_code: $("#update_map_code_" + map_id).val(),
									update_map_vocabulary: $("#update_map_vocabulary_" + map_id).val(),
									submit_update : 1
								});
}

function submit_delete_rev(data_id, map_id) 
{
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									map_id: map_id,
									submit_delete : 1
								});
}

function submit_excl_update_rev(data_id) 
{
	var exclude_status = $("input[type=radio][name=exclude_status]:checked").val();
	if ( !exclude_status ) 
	{
		alert('Nothing is selected');
		return false;
	}

	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									exclude_status: exclude_status,
									comment_text: $("#comment_text").val(),
									exclude_status_submit : 1
								});
}

function submit_approve_rev(data_id) 
{
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									submit_approve : 1
								});
}



