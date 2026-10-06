// Análisis de competidores (docs/05).

export interface CompetitorSource {
  key: string
  label: string
  hint: string
  posts: boolean
  available: boolean
  reason: string | null
}

export interface CompetitorAccount {
  id: string
  provider: string
  handle: string
  display_name: string | null
  avatar_url: string | null
  profile_url: string | null
  status: 'active' | 'error'
  last_error: string | null
  last_synced_at: string | null
  followers: number | null
}

export interface Competitor {
  id: string
  name: string
  accounts: CompetitorAccount[]
}

export interface CompetitorIndex {
  competitors: Competitor[]
  sources: CompetitorSource[]
  usage: { used: number; limit: number }
}

export interface BenchmarkRow {
  key: string
  kind: 'own' | 'competitor'
  name: string
  provider: string
  account: string
  followers: number | null
  followers_change: number | null
  followers_change_pct: number | null
  posts: number | null
  avg_engagement: number | null
  engagement_rate: number | null
  competitor: string | null
  account_id?: string
  display_name?: string | null
  avatar_url?: string | null
  profile_url?: string | null
  status?: 'active' | 'error'
  last_error?: string | null
  last_synced_at?: string | null
  weekly?: Record<string, number> | null
}

export interface BenchmarkLine {
  key: string
  label: string
  kind: 'own' | 'competitor'
  provider: string
  values: (number | null)[]
}

export interface CompetitorTopPost {
  competitor: string | null
  provider: string
  handle: string
  type: string | null
  caption: string | null
  permalink: string | null
  thumbnail_url: string | null
  published_at: string | null
  likes: number | null
  comments: number | null
  views: number | null
  engagement: number | null
}

export interface Benchmark {
  period: { days: number; from: string; to: string }
  rows: BenchmarkRow[]
  series: { dates: string[]; lines: BenchmarkLine[] }
  top_posts: CompetitorTopPost[]
}
