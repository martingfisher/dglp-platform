<?php
/**
 * The fixed lists an organisation describes itself with.
 *
 * Every list below was read out of Forum Central's member export of
 * 17 September 2026 and nothing was added to it except the wards, which
 * use the council's own list of 33 so that a filter on the directory has
 * every ward in it and not only the ones somebody has already picked.
 * A fixed list is what makes the directory searchable: free text cannot be
 * filtered, and two organisations that both do "befriending" need to say it
 * the same way for a visitor to find both.
 *
 * Keys are stable slugs and are what is stored. Labels can be reworded
 * without touching stored data.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

defined( 'ABSPATH' ) || exit;

final class Options {

	/** Organisation Type: 5 options, from the Forum Central export. */
	public static function org_type(): array {
		return [
			'abcd_pathfinder_site' => __( 'ABCD Pathfinder Site', 'dgl-platform' ),
			'community_anchor' => __( 'Community Anchor', 'dgl-platform' ),
			'community_care_hub' => __( 'Community Care Hub', 'dgl-platform' ),
			'digital_health_hub' => __( 'Digital Health Hub', 'dgl-platform' ),
			'neighbourhood_network' => __( 'Neighbourhood Network', 'dgl-platform' ),
		];
	}

	/** Legal Status: 9 options, from the Forum Central export. */
	public static function legal(): array {
		return [
			'registered_charity' => __( 'Registered Charity', 'dgl-platform' ),
			'cic_community_interest_company' => __( 'CIC (Community Interest Company)', 'dgl-platform' ),
			'cio_charitable_incorporated_organisation' => __( 'CIO (Charitable Incorporated Organisation)', 'dgl-platform' ),
			'mutual_society' => __( 'Mutual Society', 'dgl-platform' ),
			'community_benefit_society' => __( 'Community Benefit Society', 'dgl-platform' ),
			'dissolved_dormant' => __( 'Dissolved / Dormant', 'dgl-platform' ),
			'unconstituted_unincorporated_other' => __( 'Unconstituted/unincorporated/other', 'dgl-platform' ),
			'trust_foundation' => __( 'Trust / Foundation', 'dgl-platform' ),
			'private_limited_company_by_guarantee_without_share_capital' => __( 'Private limited company by guarantee without share capital', 'dgl-platform' ),
		];
	}

	/** FC specialism (relevant to organisation): 9 options, from the Forum Central export. */
	public static function specialism(): array {
		return [
			'communities_of_interest' => __( 'Communities of Interest', 'dgl-platform' ),
			'learning_disability' => __( 'Learning Disability', 'dgl-platform' ),
			'men_s_health' => __( 'Men\'s Health', 'dgl-platform' ),
			'mental_health' => __( 'Mental Health', 'dgl-platform' ),
			'older_people' => __( 'Older People', 'dgl-platform' ),
			'physical_and_sensory_impairment' => __( 'Physical and Sensory Impairment', 'dgl-platform' ),
			'trauma_informed_communities_children_and_young_people_focus' => __( 'Trauma Informed Communities (Children and Young People focus)', 'dgl-platform' ),
			'wider_health_and_social_care' => __( 'Wider Health and Social Care', 'dgl-platform' ),
			'workforce_hr' => __( 'Workforce/HR', 'dgl-platform' ),
		];
	}

	/** General Service Users: 46 options, from the Forum Central export. */
	public static function service_users(): array {
		return [
			'age_adults' => __( 'Age Groups: Adults', 'dgl-platform' ),
			'age_children_under_18' => __( 'Age Groups: Children (Under 18)', 'dgl-platform' ),
			'age_older_people' => __( 'Age Groups: Older People', 'dgl-platform' ),
			'age_young_people_16_25' => __( 'Age Groups: Young People (16-25)', 'dgl-platform' ),
			'gender_men_boys' => __( 'Gender: Men / Boys', 'dgl-platform' ),
			'gender_people_from_the_lgbtqia_community' => __( 'Gender: People from the LGBTQIA+ Community', 'dgl-platform' ),
			'gender_women_girls' => __( 'Gender: Women / Girls', 'dgl-platform' ),
			'people_from_culturally_diverse_communities' => __( 'People from Culturally Diverse Communities', 'dgl-platform' ),
			'cdc_black_and_african_diaspora' => __( 'People from Culturally Diverse Communities: Black and African Diaspora', 'dgl-platform' ),
			'cdc_chinese' => __( 'People from Culturally Diverse Communities: Chinese', 'dgl-platform' ),
			'cdc_eastern_european' => __( 'People from Culturally Diverse Communities: Eastern European', 'dgl-platform' ),
			'cdc_indian_pakistani_south_asian' => __( 'People from Culturally Diverse Communities: Indian, Pakistani, South Asian', 'dgl-platform' ),
			'cdc_irish' => __( 'People from Culturally Diverse Communities: Irish', 'dgl-platform' ),
			'people_of_faith' => __( 'People of Faith', 'dgl-platform' ),
			'people_of_faith_buddhist' => __( 'People of Faith: Buddhist', 'dgl-platform' ),
			'people_of_faith_christian' => __( 'People of Faith: Christian', 'dgl-platform' ),
			'people_of_faith_jewish' => __( 'People of Faith: Jewish', 'dgl-platform' ),
			'people_of_faith_muslim' => __( 'People of Faith: Muslim', 'dgl-platform' ),
			'people_of_faith_sikh' => __( 'People of Faith: Sikh', 'dgl-platform' ),
			'people_who_are_digitally_excluded' => __( 'People who are digitally excluded', 'dgl-platform' ),
			'access_autism_and_or_neurodiversity' => __( 'People with Accessibility Requirements: People with Autism and / or Neurodiversity', 'dgl-platform' ),
			'access_dual_sensory_loss' => __( 'People with Accessibility Requirements: People with Dual Sensory Loss', 'dgl-platform' ),
			'access_special_educational_needs_sen' => __( 'People with Accessibility Requirements: People with Special Educational Needs (SEN)', 'dgl-platform' ),
			'access_a_hearing_impairment' => __( 'People with Accessibility Requirements: People with a Hearing Impairment', 'dgl-platform' ),
			'access_a_learning_disability' => __( 'People with Accessibility Requirements: People with a Learning Disability', 'dgl-platform' ),
			'access_a_physical_impairment' => __( 'People with Accessibility Requirements: People with a Physical Impairment', 'dgl-platform' ),
			'access_a_visual_impairment' => __( 'People with Accessibility Requirements: People with a Visual Impairment', 'dgl-platform' ),
			'access_mobility_requirements' => __( 'People with Accessibility Requirements: People with mobility requirements', 'dgl-platform' ),
			'dementia' => __( 'People with Dementia', 'dgl-platform' ),
			'long_term_chronic_health_conditions' => __( 'People with long-term/chronic health conditions', 'dgl-platform' ),
			'mh_low_level_wellbeing_needs_would_benefit_from_early_intervention' => __( 'People with mental health needs: People with low level wellbeing needs/would benefit from early intervention', 'dgl-platform' ),
			'mh_low_to_moderate_mental_health_needs' => __( 'People with mental health needs: People with low to moderate mental health needs', 'dgl-platform' ),
			'mh_severe_mental_illness_enduring_and_complex_mental_health_needs' => __( 'People with mental health needs: People with severe mental illness/Enduring and complex mental health needs', 'dgl-platform' ),
			'circ_care_leavers' => __( 'People\'s circumstances: Care Leavers', 'dgl-platform' ),
			'circ_carers' => __( 'People\'s circumstances: Carers', 'dgl-platform' ),
			'circ_deprived_communities_low_income_unemployed' => __( 'People\'s circumstances: Deprived Communities/Low income/Unemployed', 'dgl-platform' ),
			'circ_families_parents_single_parents' => __( 'People\'s circumstances: Families/Parents/Single parents', 'dgl-platform' ),
			'circ_gypsy_roma_and_traveller_communities' => __( 'People\'s circumstances: Gypsy, Roma and Traveller Communities', 'dgl-platform' ),
			'circ_people_in_or_leaving_prison' => __( 'People\'s circumstances: People In or Leaving Prison', 'dgl-platform' ),
			'circ_people_experiencing_homelessness' => __( 'People\'s circumstances: People experiencing Homelessness', 'dgl-platform' ),
			'circ_people_living_in_poverty' => __( 'People\'s circumstances: People living in poverty', 'dgl-platform' ),
			'circ_people_who_suffer_from_abuse_and_or_domestic_violence' => __( 'People\'s circumstances: People who suffer from Abuse and/or Domestic Violence', 'dgl-platform' ),
			'circ_people_with_drug_and_or_alcohol_addiction_dependency' => __( 'People\'s circumstances: People with Drug and/or Alcohol Addiction/Dependency', 'dgl-platform' ),
			'circ_refugees_asylum_seekers_and_migrants' => __( 'People\'s circumstances: Refugees, Asylum Seekers and Migrants', 'dgl-platform' ),
			'circ_sex_workers' => __( 'People\'s circumstances: Sex Workers', 'dgl-platform' ),
			'circ_veterans' => __( 'People\'s circumstances: Veterans', 'dgl-platform' ),
		];
	}

	/** General Service Provision: 52 options, from the Forum Central export. */
	public static function services(): array {
		return [
			'advocacy_and_advice' => __( 'Advocacy and advice', 'dgl-platform' ),
			'care_adult_social_care_services' => __( 'Care: Adult Social Care Services', 'dgl-platform' ),
			'care_day_services' => __( 'Care: Day Services', 'dgl-platform' ),
			'care_domiciliary_care' => __( 'Care: Domiciliary Care', 'dgl-platform' ),
			'care_registered_care' => __( 'Care: Registered Care', 'dgl-platform' ),
			'care_residential_care' => __( 'Care: Residential Care', 'dgl-platform' ),
			'community_befriending' => __( 'Community: Befriending', 'dgl-platform' ),
			'community_community_centre' => __( 'Community: Community Centre', 'dgl-platform' ),
			'community_community_groups' => __( 'Community: Community Groups', 'dgl-platform' ),
			'community_intensive_community_support' => __( 'Community: Intensive Community Support', 'dgl-platform' ),
			'education' => __( 'Education', 'dgl-platform' ),
			'events_and_campaigns' => __( 'Events and Campaigns', 'dgl-platform' ),
			'food_bank_parcels' => __( 'Food Bank/Parcels', 'dgl-platform' ),
			'grant_making' => __( 'Grant Making', 'dgl-platform' ),
			'health_promotion_healthy_living' => __( 'Health Promotion/Healthy living', 'dgl-platform' ),
			'helpline' => __( 'Helpline', 'dgl-platform' ),
			'housing_support' => __( 'Housing Support', 'dgl-platform' ),
			'housing_accomodation_sheltered_housing' => __( 'Housing: Accomodation / Sheltered Housing', 'dgl-platform' ),
			'housing_crisis_service' => __( 'Housing: Crisis Service', 'dgl-platform' ),
			'housing_independent_living' => __( 'Housing: Independent Living', 'dgl-platform' ),
			'influence_policy' => __( 'Influence Policy', 'dgl-platform' ),
			'mental_health_crisis_services' => __( 'Mental Health Crisis Services', 'dgl-platform' ),
			'mental_health_service' => __( 'Mental Health Service', 'dgl-platform' ),
			'mental_health_supports_good_wellbeing' => __( 'Mental Health: Supports good wellbeing', 'dgl-platform' ),
			'peer_support' => __( 'Peer Support', 'dgl-platform' ),
			'peoples_voice_and_engagement' => __( 'Peoples\' Voice and Engagement', 'dgl-platform' ),
			'signposting' => __( 'Signposting', 'dgl-platform' ),
			'social_groups_activities' => __( 'Social groups/Activities', 'dgl-platform' ),
			'social_art' => __( 'Social groups/Activities: Art', 'dgl-platform' ),
			'social_cooking' => __( 'Social groups/Activities: Cooking', 'dgl-platform' ),
			'social_crafts' => __( 'Social groups/Activities: Crafts', 'dgl-platform' ),
			'social_dance' => __( 'Social groups/Activities: Dance', 'dgl-platform' ),
			'social_gardening' => __( 'Social groups/Activities: Gardening', 'dgl-platform' ),
			'social_green_climate_friendly_activities' => __( 'Social groups/Activities: Green/Climate-friendly Activities', 'dgl-platform' ),
			'social_music' => __( 'Social groups/Activities: Music', 'dgl-platform' ),
			'social_other_physical_activity_e_g_yoga' => __( 'Social groups/Activities: Other physical activity e.g. yoga', 'dgl-platform' ),
			'social_singing' => __( 'Social groups/Activities: Singing', 'dgl-platform' ),
			'social_sport' => __( 'Social groups/Activities: Sport', 'dgl-platform' ),
			'social_walking' => __( 'Social groups/Activities: Walking', 'dgl-platform' ),
			'support_around_bereavement' => __( 'Support around: Bereavement', 'dgl-platform' ),
			'support_around_drug_alcohol_rehabilitation' => __( 'Support around: Drug / Alcohol / Rehabilitation', 'dgl-platform' ),
			'support_around_employment' => __( 'Support around: Employment', 'dgl-platform' ),
			'support_around_hiv_prevention_and_sexual_health' => __( 'Support around: HIV Prevention and Sexual Health', 'dgl-platform' ),
			'support_around_legal_help' => __( 'Support around: Legal help', 'dgl-platform' ),
			'support_around_money_finance' => __( 'Support around: Money / Finance', 'dgl-platform' ),
			'tackling_health_inequalities' => __( 'Tackling health inequalities', 'dgl-platform' ),
			'therapy_holistic_therapies_and_wellbeing' => __( 'Therapy: Holistic Therapies and Wellbeing', 'dgl-platform' ),
			'therapy_psychological_therapies_counselling' => __( 'Therapy: Psychological Therapies / Counselling', 'dgl-platform' ),
			'training' => __( 'Training', 'dgl-platform' ),
			'transport_community_transport_minibuses' => __( 'Transport: Community Transport (Minibuses)', 'dgl-platform' ),
			'transport_wheel_chair_users_specific_mobility_needs' => __( 'Transport: Transport for wheel chair users/specific mobility needs', 'dgl-platform' ),
			'volunteering' => __( 'Volunteering', 'dgl-platform' ),
		];
	}

	/** General Service Delivery Type: 8 options, from the Forum Central export. */
	public static function delivery(): array {
		return [
			'appointments' => __( 'Appointments', 'dgl-platform' ),
			'drop_in' => __( 'Drop in', 'dgl-platform' ),
			'group_support' => __( 'Group Support', 'dgl-platform' ),
			'in_person_face_to_face' => __( 'In person/Face to face', 'dgl-platform' ),
			'one_to_one_support' => __( 'One-to-one Support', 'dgl-platform' ),
			'online' => __( 'Online', 'dgl-platform' ),
			'telephone_calls' => __( 'Telephone calls', 'dgl-platform' ),
			'text_messaging_online_chat' => __( 'Text messaging/online chat', 'dgl-platform' ),
		];
	}

	/** Accessibility Provision: 6 options, from the Forum Central export. */
	public static function accessibility(): array {
		return [
			'assistance_support_dog' => __( 'Assistance/Support Dog', 'dgl-platform' ),
			'disabled_parking' => __( 'Disabled Parking', 'dgl-platform' ),
			'easy_read' => __( 'Easy Read', 'dgl-platform' ),
			'induction_loop' => __( 'Induction Loop', 'dgl-platform' ),
			'large_print' => __( 'Large Print', 'dgl-platform' ),
			'step_free_access' => __( 'Step Free Access', 'dgl-platform' ),
		];
	}

	/** Accreditations: 7 options, from the Forum Central export. */
	public static function accreditations(): array {
		return [
			'care_quality_commission_registered' => __( 'Care Quality Commission Registered', 'dgl-platform' ),
			'disability_confident' => __( 'Disability Confident', 'dgl-platform' ),
			'living_wage_employer' => __( 'Living Wage Employer', 'dgl-platform' ),
			'mindful_employer' => __( 'Mindful Employer', 'dgl-platform' ),
			'ofsted_registered' => __( 'Ofsted Registered', 'dgl-platform' ),
			'safeguarding_standard' => __( 'Safeguarding Standard', 'dgl-platform' ),
			'volunteering_quality_mark_leeds' => __( 'Volunteering Quality Mark (Leeds)', 'dgl-platform' ),
		];
	}

	/** The 33 Leeds City Council wards, plus city-wide. */
	public static function wards(): array {
		return [
			'leeds_wide' => __( 'Leeds-wide', 'dgl-platform' ),
			'adel_and_wharfedale' => __( 'Adel and Wharfedale', 'dgl-platform' ),
			'alwoodley' => __( 'Alwoodley', 'dgl-platform' ),
			'ardsley_and_robin_hood' => __( 'Ardsley and Robin Hood', 'dgl-platform' ),
			'armley' => __( 'Armley', 'dgl-platform' ),
			'beeston_and_holbeck' => __( 'Beeston and Holbeck', 'dgl-platform' ),
			'bramley_and_stanningley' => __( 'Bramley and Stanningley', 'dgl-platform' ),
			'burmantofts_and_richmond_hill' => __( 'Burmantofts and Richmond Hill', 'dgl-platform' ),
			'calverley_and_farsley' => __( 'Calverley and Farsley', 'dgl-platform' ),
			'chapel_allerton' => __( 'Chapel Allerton', 'dgl-platform' ),
			'cross_gates_and_whinmoor' => __( 'Cross Gates and Whinmoor', 'dgl-platform' ),
			'farnley_and_wortley' => __( 'Farnley and Wortley', 'dgl-platform' ),
			'garforth_and_swillington' => __( 'Garforth and Swillington', 'dgl-platform' ),
			'gipton_and_harehills' => __( 'Gipton and Harehills', 'dgl-platform' ),
			'guiseley_and_rawdon' => __( 'Guiseley and Rawdon', 'dgl-platform' ),
			'harewood' => __( 'Harewood', 'dgl-platform' ),
			'headingley_and_hyde_park' => __( 'Headingley and Hyde Park', 'dgl-platform' ),
			'horsforth' => __( 'Horsforth', 'dgl-platform' ),
			'hunslet_and_riverside' => __( 'Hunslet and Riverside', 'dgl-platform' ),
			'killingbeck_and_seacroft' => __( 'Killingbeck and Seacroft', 'dgl-platform' ),
			'kippax_and_methley' => __( 'Kippax and Methley', 'dgl-platform' ),
			'kirkstall' => __( 'Kirkstall', 'dgl-platform' ),
			'little_london_and_woodhouse' => __( 'Little London and Woodhouse', 'dgl-platform' ),
			'middleton_park' => __( 'Middleton Park', 'dgl-platform' ),
			'moortown' => __( 'Moortown', 'dgl-platform' ),
			'morley_north' => __( 'Morley North', 'dgl-platform' ),
			'morley_south' => __( 'Morley South', 'dgl-platform' ),
			'otley_and_yeadon' => __( 'Otley and Yeadon', 'dgl-platform' ),
			'pudsey' => __( 'Pudsey', 'dgl-platform' ),
			'rothwell' => __( 'Rothwell', 'dgl-platform' ),
			'roundhay' => __( 'Roundhay', 'dgl-platform' ),
			'temple_newsam' => __( 'Temple Newsam', 'dgl-platform' ),
			'weetwood' => __( 'Weetwood', 'dgl-platform' ),
			'wetherby' => __( 'Wetherby', 'dgl-platform' ),
		];
	}

	/** Headcount bands, as Forum Central record them. */
	public static function sizes(): array {
		return [
			'1_10' => __( '1 - 10', 'dgl-platform' ),
			'11_50' => __( '11 - 50', 'dgl-platform' ),
			'51_100' => __( '51 - 100', 'dgl-platform' ),
			'100_plus' => __( '100+', 'dgl-platform' ),
		];
	}

	/**
	 * Every list, keyed by the field that uses it.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function all(): array {
		return [
			'org_type'           => self::org_type(),
			'org_legal_status'   => self::legal(),
			'org_specialism'     => self::specialism(),
			'org_service_users'  => self::service_users(),
			'org_services'       => self::services(),
			'org_delivery'       => self::delivery(),
			'org_accessibility'  => self::accessibility(),
			'org_accreditations' => self::accreditations(),
			'org_ward'           => self::wards(),
			'org_staff'          => self::sizes(),
			'org_volunteers'     => self::sizes(),
		];
	}

	/**
	 * The option key whose label matches the given text, or null.
	 *
	 * Case and surrounding space are ignored. Used by the import, which has
	 * labels and needs keys.
	 *
	 * @param array<string, string> $options
	 */
	public static function key_for( array $options, string $label ): ?string {
		$wanted = strtolower( trim( $label ) );

		foreach ( $options as $key => $text ) {
			if ( strtolower( trim( (string) $text ) ) === $wanted ) {
				return (string) $key;
			}
		}

		return null;
	}
}
